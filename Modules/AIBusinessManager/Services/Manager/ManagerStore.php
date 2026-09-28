<?php

namespace Modules\AIBusinessManager\Services\Manager;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes Eli-owned tables only (ai_bm_*). Nothing here touches TeamPOS records.
 */
class ManagerStore
{
    public const TARGET_METRICS = ['revenue', 'gross_profit', 'invoices', 'waste_pct', 'sell_through_pct'];

    public const COST_CATEGORIES = ['rent', 'labour', 'utilities', 'transport', 'other'];

    public const EVENT_KINDS = ['term', 'event', 'closure', 'promo'];

    public function __construct(private int $businessId)
    {
    }

    public function available(): bool
    {
        return EliSchema::ready();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function targets(?Carbon $month = null): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table('ai_bm_targets')
            ->where('business_id', $this->businessId)
            ->when($month !== null, fn ($q) => $q->where('month', $month->copy()->startOfMonth()->toDateString()))
            ->orderBy('month')
            ->orderBy('location_id')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function replaceTargets(array $rows, int $userId): void
    {
        if (! $this->available()) {
            return;
        }
        DB::transaction(function () use ($rows, $userId) {
            DB::table('ai_bm_targets')->where('business_id', $this->businessId)->delete();
            foreach ($rows as $row) {
                DB::table('ai_bm_targets')->insert([
                    'business_id' => $this->businessId,
                    'location_id' => $row['location_id'] ?: null,
                    'metric' => $row['metric'],
                    'month' => Carbon::parse($row['month'])->startOfMonth()->toDateString(),
                    'value' => $row['value'],
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * Fixed costs active at any point in the range.
     *
     * @return list<array<string, mixed>>
     */
    public function fixedCosts(?Carbon $start = null, ?Carbon $end = null): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table('ai_bm_fixed_costs')
            ->where('business_id', $this->businessId)
            ->when($end !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('starts_on')->orWhere('starts_on', '<=', $end->toDateString())))
            ->when($start !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $start->toDateString())))
            ->orderBy('location_id')
            ->orderBy('name')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function replaceFixedCosts(array $rows, int $userId): void
    {
        if (! $this->available()) {
            return;
        }
        DB::transaction(function () use ($rows, $userId) {
            DB::table('ai_bm_fixed_costs')->where('business_id', $this->businessId)->delete();
            foreach ($rows as $row) {
                DB::table('ai_bm_fixed_costs')->insert([
                    'business_id' => $this->businessId,
                    'location_id' => $row['location_id'] ?: null,
                    'name' => $row['name'],
                    'category' => $row['category'],
                    'monthly_amount' => $row['monthly_amount'],
                    'starts_on' => $row['starts_on'] ?: null,
                    'ends_on' => $row['ends_on'] ?: null,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calendarEvents(Carbon $start, Carbon $end): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table('ai_bm_calendar_events')
            ->where('business_id', $this->businessId)
            ->where('starts_on', '<=', $end->toDateString())
            ->where('ends_on', '>=', $start->toDateString())
            ->orderBy('starts_on')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allCalendarEvents(): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table('ai_bm_calendar_events')
            ->where('business_id', $this->businessId)
            ->orderBy('starts_on')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function replaceCalendarEvents(array $rows, int $userId): void
    {
        if (! $this->available()) {
            return;
        }
        DB::transaction(function () use ($rows, $userId) {
            DB::table('ai_bm_calendar_events')->where('business_id', $this->businessId)->delete();
            foreach ($rows as $row) {
                DB::table('ai_bm_calendar_events')->insert([
                    'business_id' => $this->businessId,
                    'location_id' => $row['location_id'] ?: null,
                    'name' => $row['name'],
                    'kind' => $row['kind'],
                    'starts_on' => $row['starts_on'],
                    'ends_on' => $row['ends_on'] ?: $row['starts_on'],
                    'effect_pct' => ($row['effect_pct'] ?? '') === '' ? null : $row['effect_pct'],
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $note
     */
    public function addNote(array $note, int $userId): ?int
    {
        if (! $this->available()) {
            return null;
        }

        return (int) DB::table('ai_bm_notes')->insertGetId([
            'business_id' => $this->businessId,
            'kind' => $note['kind'],
            'title' => mb_substr((string) $note['title'], 0, 255),
            'details' => $note['details'] ?? null,
            'location_id' => $note['location_id'] ?? null,
            'product_id' => $note['product_id'] ?? null,
            'effective_on' => $note['effective_on'] ?? null,
            'due_on' => $note['due_on'] ?? null,
            'status' => 'open',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function completeNote(int $id): bool
    {
        if (! $this->available()) {
            return false;
        }

        return DB::table('ai_bm_notes')
            ->where('business_id', $this->businessId)
            ->where('id', $id)
            ->update(['status' => 'done', 'completed_at' => now(), 'updated_at' => now()]) > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function notes(?string $kind = null, ?string $status = null, int $limit = 50): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table('ai_bm_notes')
            ->where('business_id', $this->businessId)
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function note(int $id): ?array
    {
        if (! $this->available()) {
            return null;
        }
        $row = DB::table('ai_bm_notes')->where('business_id', $this->businessId)->where('id', $id)->first();

        return $row ? (array) $row : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function saveForecasts(array $rows, Carbon $madeOn): void
    {
        if (! $this->available() || $rows === [] || ! config('aibusinessmanager.save_forecasts', true)) {
            return;
        }
        $now = now();
        $payload = array_map(fn ($r) => [
            'business_id' => $this->businessId,
            'location_id' => $r['location_id'],
            'product_id' => $r['product_id'],
            'target_date' => $r['target_date'],
            'made_on' => $madeOn->toDateString(),
            'model' => $r['model'],
            'p10' => $r['p10'],
            'p50' => $r['p50'],
            'p90' => $r['p90'],
            'recommended_qty' => $r['recommended_qty'] ?? null,
            'unit_price' => $r['unit_price'] ?? null,
            'unit_cost' => $r['unit_cost'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);
        foreach (array_chunk($payload, 200) as $chunk) {
            DB::table('ai_bm_forecasts')->upsert(
                $chunk,
                ['business_id', 'location_id', 'product_id', 'target_date', 'made_on'],
                ['model', 'p10', 'p50', 'p90', 'recommended_qty', 'unit_price', 'unit_cost', 'updated_at']
            );
        }
    }

    /**
     * Earliest saved forecast per target date / location / product (the one the plan was made from).
     *
     * @param  list<int>|null  $locationIds
     * @return list<array<string, mixed>>
     */
    public function savedForecasts(Carbon $start, Carbon $end, ?array $locationIds): array
    {
        if (! $this->available()) {
            return [];
        }
        $rows = DB::table('ai_bm_forecasts')
            ->where('business_id', $this->businessId)
            ->whereBetween('target_date', [$start->toDateString(), $end->toDateString()])
            ->whereColumn('made_on', '<', 'target_date')
            ->when($locationIds !== null, fn ($q) => $q->whereIn('location_id', $locationIds === [] ? [0] : $locationIds))
            ->orderBy('made_on', 'desc')
            ->get();
        $latest = [];
        foreach ($rows as $r) {
            $latest[$r->target_date.':'.$r->location_id.':'.$r->product_id] = (array) $r;
        }

        return array_values($latest);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function brief(Carbon $date, string $kind = 'daily'): ?array
    {
        if (! $this->available()) {
            return null;
        }
        $row = DB::table('ai_bm_briefs')
            ->where('business_id', $this->businessId)
            ->where('brief_date', $date->toDateString())
            ->where('kind', $kind)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveBrief(Carbon $date, string $content, array $payload, string $kind = 'daily'): void
    {
        if (! $this->available()) {
            return;
        }
        DB::table('ai_bm_briefs')->upsert([[
            'business_id' => $this->businessId,
            'brief_date' => $date->toDateString(),
            'kind' => $kind,
            'content' => $content,
            'payload' => json_encode($payload),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['business_id', 'brief_date', 'kind'], ['content', 'payload', 'updated_at']);
    }

    /**
     * Returns true when the alert is new for that day.
     */
    public function raiseAlert(Carbon $date, ?int $locationId, string $code, string $severity, string $message): bool
    {
        if (! $this->available()) {
            return false;
        }
        $exists = DB::table('ai_bm_alerts')
            ->where('business_id', $this->businessId)
            ->where('alert_date', $date->toDateString())
            ->where('code', $code)
            ->where(fn ($q) => $locationId === null ? $q->whereNull('location_id') : $q->where('location_id', $locationId))
            ->exists();
        if ($exists) {
            return false;
        }
        DB::table('ai_bm_alerts')->insert([
            'business_id' => $this->businessId,
            'location_id' => $locationId,
            'alert_date' => $date->toDateString(),
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function alerts(Carbon $since, bool $unseenOnly = false): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table('ai_bm_alerts')
            ->where('business_id', $this->businessId)
            ->where('alert_date', '>=', $since->toDateString())
            ->when($unseenOnly, fn ($q) => $q->whereNull('seen_at'))
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    public function markAlertsSeen(): void
    {
        if (! $this->available()) {
            return;
        }
        DB::table('ai_bm_alerts')
            ->where('business_id', $this->businessId)
            ->whereNull('seen_at')
            ->update(['seen_at' => now()]);
    }
}
