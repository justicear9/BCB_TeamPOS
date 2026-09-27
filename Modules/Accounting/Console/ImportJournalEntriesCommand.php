<?php

namespace Modules\Accounting\Console;

use App\Business;
use App\Utils\Util;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\AccountingAccount;
use Modules\Accounting\Entities\AccountingAccountsTransaction;
use Modules\Accounting\Entities\AccountingAccTransMapping;
use Modules\Accounting\Utils\AccountingUtil;
use App\BusinessLocation;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportJournalEntriesCommand extends Command
{
    protected $signature = 'accounting:import-journal
                            {business : Business name (exact match) or numeric business id}
                            {--file= : Absolute or relative path to XLSX/CSV file}
                            {--user=1 : User id for created_by}
                            {--location= : Optional location id or exact location name}
                            {--prefix=IMPJ : Ref prefix used as <prefix>-<Journal No>}
                            {--dry-run : Validate and report only; no writes}';

    protected $description = 'Bulk import journal entries from spreadsheet grouped by Journal No.';

    public function handle(Util $util, AccountingUtil $accountingUtil): int
    {
        $businessArg = (string) $this->argument('business');
        $business = is_numeric($businessArg)
            ? Business::find((int) $businessArg)
            : Business::where('name', $businessArg)->first();

        if (! $business) {
            $this->error('Business not found: '.$businessArg);

            return self::FAILURE;
        }

        $path = (string) ($this->option('file') ?: '');
        if ($path === '') {
            $this->error('Pass --file=<path-to-journal-file>.');

            return self::FAILURE;
        }
        if ($path[0] !== '/' && ! preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            $path = base_path($path);
        }
        if (! is_readable($path)) {
            $this->error('File not readable: '.$path);

            return self::FAILURE;
        }

        $userId = (int) ($this->option('user') ?: 1);
        $prefix = trim((string) ($this->option('prefix') ?: 'IMPJ'));
        $dryRun = (bool) $this->option('dry-run');
        $businessId = (int) $business->id;
        $locationId = $this->resolveLocationId($businessId, $this->option('location'));

        $this->info('Business: '.$business->name.' (id '.$businessId.')');
        $this->line('File: '.$path);
        if ($dryRun) {
            $this->warn('Dry run mode enabled.');
        }

        $sheet = IOFactory::load($path)->getSheet(0);
        $highestRow = (int) $sheet->getHighestDataRow();
        $highestCol = $sheet->getHighestDataColumn();

        if ($highestRow < 2) {
            $this->warn('No data rows found.');

            return self::SUCCESS;
        }

        $headerMap = $this->buildHeaderMap($sheet->rangeToArray("A1:{$highestCol}1", null, true, true, true)[1] ?? []);
        foreach (['date', 'journal_no', 'description', 'gl_code', 'account', 'debit', 'credit'] as $required) {
            if (! isset($headerMap[$required])) {
                $this->error('Missing required column for: '.$required);

                return self::FAILURE;
            }
        }

        $accountsByGl = AccountingAccount::where('business_id', $businessId)
            ->whereNotNull('gl_code')
            ->get()
            ->keyBy(fn ($a) => strtolower(trim((string) $a->gl_code)));

        $accountsByName = AccountingAccount::where('business_id', $businessId)
            ->get()
            ->keyBy(fn ($a) => strtolower(trim((string) $a->name)));

        $groups = [];
        for ($r = 2; $r <= $highestRow; $r++) {
            $row = $sheet->rangeToArray("A{$r}:{$highestCol}{$r}", null, true, true, true)[$r] ?? [];
            $journalNo = trim((string) ($row[$headerMap['journal_no']] ?? ''));
            if ($journalNo === '') {
                continue;
            }

            $groups[$journalNo][] = [
                'row_num' => $r,
                'date' => $row[$headerMap['date']] ?? '',
                'description' => trim((string) ($row[$headerMap['description']] ?? '')),
                'gl_code' => trim((string) ($row[$headerMap['gl_code']] ?? '')),
                'account' => trim((string) ($row[$headerMap['account']] ?? '')),
                'debit' => $this->parseAmount($row[$headerMap['debit']] ?? null),
                'credit' => $this->parseAmount($row[$headerMap['credit']] ?? null),
            ];
        }

        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($groups as $journalNo => $lines) {
            $refNo = ($prefix !== '' ? $prefix.'-' : '').$journalNo;

            if (AccountingAccTransMapping::where('business_id', $businessId)->where('type', 'journal_entry')->where('ref_no', $refNo)->exists()) {
                $skipped++;
                continue;
            }

            try {
                $opDate = $this->parseJournalDate($lines[0]['date']);
                $accountingUtil->assertOperationDateNotLocked($businessId, $opDate);
            } catch (\Throwable $e) {
                $errors[] = "Journal {$journalNo}: invalid/locked date.";
                continue;
            }

            $debitTotal = 0.0;
            $creditTotal = 0.0;
            $preparedLines = [];

            foreach ($lines as $line) {
                $glKey = strtolower(trim((string) $line['gl_code']));
                $nameKey = strtolower(trim((string) $line['account']));
                $account = $glKey !== '' ? ($accountsByGl[$glKey] ?? null) : null;
                if (! $account && $nameKey !== '') {
                    $account = $accountsByName[$nameKey] ?? null;
                }
                if (! $account) {
                    $errors[] = "Journal {$journalNo} row {$line['row_num']}: account not found (GL {$line['gl_code']}, {$line['account']}).";
                    continue 2;
                }

                $debit = (float) $line['debit'];
                $credit = (float) $line['credit'];
                if ($debit <= 0 && $credit <= 0) {
                    continue;
                }
                if ($debit > 0 && $credit > 0) {
                    $errors[] = "Journal {$journalNo} row {$line['row_num']}: both debit and credit are set.";
                    continue 2;
                }

                $preparedLines[] = [
                    'accounting_account_id' => (int) $account->id,
                    'amount' => $debit > 0 ? $debit : $credit,
                    'type' => $debit > 0 ? 'debit' : 'credit',
                    'line_note' => $line['description'] !== '' ? $line['description'] : null,
                ];
                $debitTotal += $debit;
                $creditTotal += $credit;
            }

            if (empty($preparedLines)) {
                $errors[] = "Journal {$journalNo}: no valid lines.";
                continue;
            }

            if (round($debitTotal, 2) !== round($creditTotal, 2)) {
                $errors[] = "Journal {$journalNo}: not balanced (debit {$debitTotal} vs credit {$creditTotal}).";
                continue;
            }

            if ($dryRun) {
                $created++;
                continue;
            }

            DB::beginTransaction();
            try {
                $mapping = new AccountingAccTransMapping();
                $mapping->business_id = $businessId;
                $mapping->ref_no = $refNo;
                $mapping->note = $lines[0]['description'] !== '' ? $lines[0]['description'] : null;
                $mapping->type = 'journal_entry';
                $mapping->created_by = $userId;
                $mapping->operation_date = $this->parseJournalDate($lines[0]['date']);
                $mapping->save();

                foreach ($preparedLines as $line) {
                    $row = new AccountingAccountsTransaction();
                    $row->fill([
                        'accounting_account_id' => $line['accounting_account_id'],
                        'amount' => $line['amount'],
                        'type' => $line['type'],
                        'note' => $line['line_note'],
                        'location_id' => $locationId,
                        'created_by' => $userId,
                        'operation_date' => $mapping->operation_date,
                        'sub_type' => 'journal_entry',
                        'acc_trans_mapping_id' => $mapping->id,
                    ]);
                    $row->save();
                }

                DB::commit();
                $created++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $errors[] = "Journal {$journalNo}: ".$e->getMessage();
            }
        }

        $this->info('Imported journals: '.$created);
        $this->line('Skipped existing: '.$skipped);
        $this->line('Errors: '.count($errors));
        foreach (array_slice($errors, 0, 25) as $error) {
            $this->warn($error);
        }
        if (count($errors) > 25) {
            $this->warn('... and '.(count($errors) - 25).' more.');
        }

        return count($errors) > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function parseAmount($value): float
    {
        if ($value === null) {
            return 0.0;
        }
        $raw = trim((string) $value);
        if ($raw === '' || $raw === '-') {
            return 0.0;
        }
        $raw = str_replace([',', ' '], '', $raw);

        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    protected function parseJournalDate($value): Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value));
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            throw new \RuntimeException('Empty date');
        }

        // Example in file: Monday, January 05, 2026
        try {
            return Carbon::createFromFormat('l, F d, Y', $raw)->startOfDay();
        } catch (\Throwable $e) {
            return Carbon::parse($raw);
        }
    }

    protected function normalizeHeader(string $header): string
    {
        $h = strtolower(trim($header));
        $h = preg_replace('/[^a-z0-9]+/', '_', $h);

        return trim((string) $h, '_');
    }

    protected function buildHeaderMap(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $col => $name) {
            $normalized[$this->normalizeHeader((string) $name)] = $col;
        }

        $map = [];
        $map['date'] = $normalized['date'] ?? null;
        $map['journal_no'] = $normalized['journal_no'] ?? null;
        $map['description'] = $normalized['description'] ?? null;
        $map['gl_code'] = $normalized['gl_code'] ?? null;
        $map['account'] = $normalized['account'] ?? null;
        $map['debit'] = $normalized['debit_gh'] ?? ($normalized['debit'] ?? null);
        $map['credit'] = $normalized['credit_gh'] ?? ($normalized['credit'] ?? null);

        return array_filter($map);
    }

    protected function resolveLocationId(int $businessId, $locationOption): ?int
    {
        if ($locationOption === null || $locationOption === '') {
            return null;
        }

        if (ctype_digit((string) $locationOption)) {
            $location = BusinessLocation::where('business_id', $businessId)->where('id', (int) $locationOption)->first();
            if (! $location) {
                throw new \RuntimeException('Location id not found for business: '.$locationOption);
            }

            return (int) $location->id;
        }

        $location = BusinessLocation::where('business_id', $businessId)
            ->where('name', (string) $locationOption)
            ->first();
        if (! $location) {
            throw new \RuntimeException('Location name not found for business: '.$locationOption);
        }

        return (int) $location->id;
    }
}

