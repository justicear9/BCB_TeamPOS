<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Str;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\Accounting\Entities\AccountingAccountType;

class CoaCsvImportService
{
    /**
     * @return array{created: int, updated: int, errors: array<int, string>}
     */
    public function importFile(string $absolutePath, int $businessId, int $userId): array
    {
        $handle = fopen($absolutePath, 'r');
        if ($handle === false) {
            return ['created' => 0, 'updated' => 0, 'errors' => ['Could not open file']];
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return ['created' => 0, 'updated' => 0, 'errors' => ['Empty file']];
        }

        $headerMap = $this->normalizeImportHeader($header);
        $required = ['name', 'account_primary_type', 'account_sub_type', 'detail_type'];
        foreach ($required as $requiredCol) {
            if (! array_key_exists($requiredCol, $headerMap)) {
                fclose($handle);

                return ['created' => 0, 'updated' => 0, 'errors' => ['Missing columns: '.implode(', ', $required)]];
            }
        }

        $created = 0;
        $updated = 0;
        $errors = [];
        $lineNo = 1;
        $subTypeCache = [];
        $detailTypeCache = [];
        $parentCache = AccountingAccount::where('business_id', $businessId)->pluck('id', 'name')->toArray();

        while (($row = fgetcsv($handle)) !== false) {
            $lineNo++;
            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $data = $this->mapImportRow($headerMap, $row);
            try {
                $normalized = $this->normalizeImportData($data, $businessId, $subTypeCache, $detailTypeCache, $parentCache);
                $payload = [
                    'name' => $normalized['name'],
                    'business_id' => $businessId,
                ];
                $values = [
                    'gl_code' => $normalized['gl_code'],
                    'account_primary_type' => $normalized['account_primary_type'],
                    'account_sub_type_id' => $normalized['account_sub_type_id'],
                    'detail_type_id' => $normalized['detail_type_id'],
                    'parent_account_id' => $normalized['parent_account_id'],
                    'status' => $normalized['status'],
                    'description' => $normalized['description'],
                    'is_cash_account' => $normalized['is_cash_account'],
                    'created_by' => $userId,
                ];

                $existing = AccountingAccount::where($payload)->first();
                if ($existing) {
                    $existing->update($values);
                    $updated++;
                    $parentCache[$existing->name] = $existing->id;
                } else {
                    $account = AccountingAccount::create(array_merge($payload, $values));
                    $created++;
                    $parentCache[$account->name] = $account->id;
                }
            } catch (\Throwable $e) {
                $errors[] = "Line {$lineNo}: ".$e->getMessage();
            }
        }

        fclose($handle);

        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }

    public function normalizeImportHeader(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $col) {
            $key = Str::of((string) $col)->lower()->trim()->replace(['-', ' '], '_')->value();
            if ($key !== '') {
                $map[$key] = $idx;
            }
        }

        return $map;
    }

    public function mapImportRow(array $headerMap, array $row): array
    {
        $data = [];
        foreach ($headerMap as $key => $idx) {
            $data[$key] = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
        }

        return $data;
    }

    public function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, int|null>  $parentCache
     */
    public function normalizeImportData(array $row, int $business_id, array &$subTypeCache, array &$detailTypeCache, array $parentCache): array
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Name is required');
        }

        $primary = Str::lower(trim((string) ($row['account_primary_type'] ?? '')));
        if ($primary === 'expense') {
            $primary = 'expenses';
        }
        if (! in_array($primary, ['asset', 'liability', 'equity', 'income', 'expenses'], true)) {
            throw new \RuntimeException('Invalid account_primary_type');
        }

        $subTypeRaw = trim((string) ($row['account_sub_type'] ?? ''));
        $subTypeId = $this->resolveAccountTypeId($subTypeRaw, 'sub_type', $business_id, $subTypeCache);
        if ($subTypeId === null) {
            throw new \RuntimeException('Invalid account_sub_type: '.$subTypeRaw);
        }

        $detailRaw = trim((string) ($row['detail_type'] ?? ''));
        $detailTypeId = $this->resolveAccountTypeId($detailRaw, 'detail_type', $business_id, $detailTypeCache, $subTypeId);
        if ($detailTypeId === null) {
            throw new \RuntimeException('Invalid detail_type: '.$detailRaw);
        }

        $parentId = null;
        $parentRaw = trim((string) ($row['parent_account'] ?? ''));
        if ($parentRaw !== '') {
            if (is_numeric($parentRaw)) {
                $candidate = AccountingAccount::where('business_id', $business_id)->where('id', (int) $parentRaw)->value('id');
                if ($candidate === null) {
                    throw new \RuntimeException('Invalid parent_account');
                }
                $parentId = (int) $candidate;
            } else {
                if (! isset($parentCache[$parentRaw])) {
                    throw new \RuntimeException('Invalid parent_account');
                }
                $parentId = (int) $parentCache[$parentRaw];
            }
        }

        $status = Str::lower(trim((string) ($row['status'] ?? 'active')));
        $status = in_array($status, ['active', 'inactive'], true) ? $status : 'active';

        $isCash = $this->csvBool($row['is_cash_account'] ?? null);

        return [
            'name' => $name,
            'gl_code' => trim((string) ($row['gl_code'] ?? '')) ?: null,
            'account_primary_type' => $primary,
            'account_sub_type_id' => $subTypeId,
            'detail_type_id' => $detailTypeId,
            'parent_account_id' => $parentId,
            'status' => $status,
            'description' => trim((string) ($row['description'] ?? '')) ?: null,
            'is_cash_account' => $isCash,
        ];
    }

    public function resolveAccountTypeId(string $raw, string $type, int $business_id, array &$cache, ?int $parentId = null): ?int
    {
        $cacheKey = $type.'|'.$parentId.'|'.Str::lower($raw);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $query = AccountingAccountType::where('account_type', $type)
            ->where(function ($q) use ($business_id) {
                $q->whereNull('business_id')->orWhere('business_id', $business_id);
            });

        if ($parentId !== null) {
            $query->where('parent_id', $parentId);
        }

        if (is_numeric($raw)) {
            $queryById = clone $query;
            $id = (int) $queryById->where('id', (int) $raw)->value('id');
            if ($id > 0) {
                $cache[$cacheKey] = $id;

                return $id;
            }
        }

        $trimmed = trim($raw);
        // Prefer exact slug match (detail types use hyphens e.g. supplies_and_materials_-_cos).
        $id = (int) (clone $query)->whereRaw('LOWER(name) = ?', [Str::lower($trimmed)])->value('id');
        if ($id > 0) {
            $cache[$cacheKey] = $id;

            return $id;
        }

        $normalized = Str::of($raw)->lower()->trim()->replace([' ', '-'], '_')->value();
        $id = (int) $query->whereRaw('LOWER(name) = ?', [$normalized])->value('id');
        if ($id > 0) {
            $cache[$cacheKey] = $id;

            return $id;
        }

        $cache[$cacheKey] = null;

        return null;
    }

    public function csvBool($raw): bool
    {
        $val = Str::lower(trim((string) $raw));

        return in_array($val, ['1', 'true', 'yes', 'y'], true);
    }
}
