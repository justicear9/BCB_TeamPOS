<?php

namespace Modules\AIBusinessManager\Services\Manager;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily stock movements per location and product, rebuilt from TeamPOS records (base units).
 *
 * Opening stock at the range start = current variation_location_details stock minus every movement
 * since the range start. Each day then replays the movements in time order, which gives the stock
 * left after the last sale and whether the product ran out before the shop's usual last sale.
 */
class StockLedger
{
    public const KINDS = ['produced', 'purchased', 'opening_stock', 'transfer_in', 'transfer_out', 'sold', 'consumed', 'adjusted'];

    private const SOLD_OUT_THRESHOLD = 0.5;

    /** @var array<string, array<string, mixed>> */
    private array $products = [];

    /** @var array<int, array<int, float>> location_id => weekday => usual last sale, hours since midnight */
    private array $usualClose = [];

    /**
     * location_id => true when most sales are entered during trading hours (06:00–17:00).
     * Shops that key the day's sales in one batch at night have no usable sell-out time.
     *
     * @var array<int, bool>
     */
    private array $liveEntry = [];

    public function __construct(private ManagerScope $scope)
    {
    }

    /**
     * @param  list<int>|null  $productIds
     * @return array{days: array<string, array<string, array<string, mixed>>>, products: array<int, array<string, mixed>>, usual_close: array<int, array<int, float>>}
     */
    public function build(Carbon $start, Carbon $end, ?array $productIds = null, bool $onlyRecipeProducts = false): array
    {
        $tz = $this->scope->timezone;
        $start = $start->copy()->setTimezone($tz)->startOfDay();
        $end = $end->copy()->setTimezone($tz)->endOfDay();

        $productFilter = $productIds;
        if ($onlyRecipeProducts) {
            $recipeIds = $this->recipeProductIds();
            $productFilter = $productFilter === null ? $recipeIds : array_values(array_intersect($productFilter, $recipeIds));
        }

        $events = $this->events($start, $productFilter);
        $current = $this->currentStock($productFilter);

        $sinceStart = [];
        foreach ($events as $e) {
            $key = $e['l'].':'.$e['p'];
            $sinceStart[$key] = ($sinceStart[$key] ?? 0.0) + $e['d'];
        }
        $opening = [];
        foreach (array_unique(array_merge(array_keys($current), array_keys($sinceStart))) as $key) {
            $opening[$key] = ($current[$key] ?? 0.0) - ($sinceStart[$key] ?? 0.0);
        }

        $byDay = [];
        foreach ($events as $e) {
            $byDay[$e['day']][] = $e;
        }
        ksort($byDay);

        $this->usualClose = $this->usualLastSale($events);

        $stock = $opening;
        $days = [];
        $lastDay = $end->toDateString();
        foreach ($byDay as $day => $dayEvents) {
            if ($day < $start->toDateString()) {
                continue;
            }
            $inRange = $day <= $lastDay;
            usort($dayEvents, fn ($a, $b) => $a['ts'] <=> $b['ts']);

            $rows = [];
            foreach ($dayEvents as $e) {
                $key = $e['l'].':'.$e['p'];
                if (! isset($rows[$key])) {
                    $rows[$key] = $this->emptyRow($e['l'], $e['p'], $stock[$key] ?? 0.0);
                }
                $stock[$key] = ($stock[$key] ?? 0.0) + $e['d'];
                if (! $inRange) {
                    continue;
                }
                $row = &$rows[$key];
                $row[$e['k']] += abs($e['d']);
                if ($e['k'] === 'produced') {
                    $row['production_waste'] += $e['w'];
                }
                if ($e['k'] === 'sold') {
                    $row['revenue'] += $e['r'];
                    $row['first_sale_ts'] ??= $e['ts'];
                    $row['last_sale_ts'] = $e['ts'];
                    $row['stock_after_last_sale'] = $stock[$key];
                    $row['_out_after_sale'] = 0.0;
                } elseif ($e['k'] === 'transfer_out' && $row['last_sale_ts'] !== null) {
                    $row['_out_after_sale'] += abs($e['d']);
                }
                $row['closing'] = $stock[$key];
                unset($row);
            }
            if (! $inRange) {
                continue;
            }
            $weekday = (int) Carbon::parse($day, $tz)->dayOfWeek;
            foreach ($rows as $key => $row) {
                $days[$day][$key] = $this->finishRow($row, $weekday, $day);
            }
        }

        $this->loadProducts(array_values(array_unique(
            array_map(fn ($k) => (int) explode(':', $k)[1], array_keys($opening))
        )));

        return [
            'days' => $days,
            'opening' => $opening,
            'products' => $this->products,
            'usual_close' => $this->usualClose,
            'live_entry' => $this->liveEntry,
        ];
    }

    /**
     * Continuous daily series per location:product, filling days without movements (stock carried forward, zero sales).
     *
     * @param  array{days: array<string, array<string, array<string, mixed>>>, opening: array<string, float>}  $ledger
     * @return array<string, array<string, array<string, mixed>>> key => date => row
     */
    public function series(array $ledger, Carbon $start, Carbon $end): array
    {
        $keys = array_keys($ledger['opening']);
        foreach ($ledger['days'] as $rows) {
            foreach (array_keys($rows) as $key) {
                $keys[] = $key;
            }
        }
        $keys = array_values(array_unique($keys));

        $out = [];
        foreach ($keys as $key) {
            $stock = $ledger['opening'][$key] ?? 0.0;
            [$locationId, $productId] = array_map('intval', explode(':', $key));
            $cursor = $start->copy()->startOfDay();
            while ($cursor->lte($end)) {
                $date = $cursor->toDateString();
                $row = $ledger['days'][$date][$key] ?? null;
                if ($row === null) {
                    $row = $this->finishRow($this->emptyRow($locationId, $productId, $stock), (int) $cursor->dayOfWeek, $date);
                }
                $stock = $row['closing'];
                $out[$key][$date] = $row;
                $cursor->addDay();
            }
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    public function recipeProductIds(): array
    {
        return DB::table('mfg_recipes as r')
            ->join('products as p', 'p.id', '=', 'r.product_id')
            ->where('p.business_id', $this->scope->businessId)
            ->pluck('p.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Locations that record production output.
     *
     * @return list<int>
     */
    public function productionLocationIds(Carbon $since): array
    {
        return DB::table('transactions')
            ->where('business_id', $this->scope->businessId)
            ->where('type', 'production_purchase')
            ->where('transaction_date', '>=', $since->toDateTimeString())
            ->distinct()
            ->pluck('location_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>|null  $productIds
     * @return list<array{l: int, p: int, ts: int, day: string, k: string, d: float, r: float, w: float}>
     */
    private function events(Carbon $start, ?array $productIds): array
    {
        $businessId = $this->scope->businessId;
        $locationIds = $this->scope->locationIds;
        $from = $start->toDateTimeString();
        $events = [];

        $sellTypes = ['sell' => 'sold', 'sell_transfer' => 'transfer_out', 'production_sell' => 'consumed'];
        $sells = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->where('t.business_id', $businessId)
            ->whereIn('t.type', array_keys($sellTypes))
            ->where('t.status', 'final')
            ->where('p.enable_stock', 1)
            ->whereNull('tsl.parent_sell_line_id')
            ->where('t.transaction_date', '>=', $from)
            ->when($locationIds !== null, fn ($q) => $q->whereIn('t.location_id', $locationIds === [] ? [0] : $locationIds))
            ->when($productIds !== null, fn ($q) => $q->whereIn('tsl.product_id', $productIds === [] ? [0] : $productIds))
            ->select([
                't.location_id', 'tsl.product_id', 't.type', 't.transaction_date',
                DB::raw('(tsl.quantity - COALESCE(tsl.quantity_returned, 0)) as qty'),
                DB::raw('(tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * tsl.unit_price_inc_tax as revenue'),
            ])
            ->get();
        foreach ($sells as $r) {
            $qty = (float) $r->qty;
            if ($qty == 0.0) {
                continue;
            }
            $events[] = $this->event($r->location_id, $r->product_id, $r->transaction_date, $sellTypes[$r->type], -$qty, $r->type === 'sell' ? (float) $r->revenue : 0.0);
        }

        $purchaseTypes = ['production_purchase' => 'produced', 'purchase' => 'purchased', 'opening_stock' => 'opening_stock', 'purchase_transfer' => 'transfer_in'];
        $purchases = DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->join('products as p', 'p.id', '=', 'pl.product_id')
            ->where('t.business_id', $businessId)
            ->whereIn('t.type', array_keys($purchaseTypes))
            ->where('t.status', 'received')
            ->where('p.enable_stock', 1)
            ->where('t.transaction_date', '>=', $from)
            ->when($locationIds !== null, fn ($q) => $q->whereIn('t.location_id', $locationIds === [] ? [0] : $locationIds))
            ->when($productIds !== null, fn ($q) => $q->whereIn('pl.product_id', $productIds === [] ? [0] : $productIds))
            ->select([
                't.location_id', 'pl.product_id', 't.type', 't.transaction_date',
                DB::raw('(pl.quantity - COALESCE(pl.quantity_returned, 0)) as qty'),
                DB::raw('COALESCE(t.mfg_wasted_units, 0) as wasted'),
            ])
            ->get();
        foreach ($purchases as $r) {
            $qty = (float) $r->qty;
            if ($qty == 0.0) {
                continue;
            }
            $e = $this->event($r->location_id, $r->product_id, $r->transaction_date, $purchaseTypes[$r->type], $qty, 0.0);
            $e['w'] = $r->type === 'production_purchase' ? (float) $r->wasted : 0.0;
            $events[] = $e;
        }

        $adjustments = DB::table('stock_adjustment_lines as sal')
            ->join('transactions as t', 't.id', '=', 'sal.transaction_id')
            ->join('products as p', 'p.id', '=', 'sal.product_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'stock_adjustment')
            ->where('p.enable_stock', 1)
            ->where('t.transaction_date', '>=', $from)
            ->when($locationIds !== null, fn ($q) => $q->whereIn('t.location_id', $locationIds === [] ? [0] : $locationIds))
            ->when($productIds !== null, fn ($q) => $q->whereIn('sal.product_id', $productIds === [] ? [0] : $productIds))
            ->select(['t.location_id', 'sal.product_id', 't.transaction_date', 'sal.quantity'])
            ->get();
        foreach ($adjustments as $r) {
            $qty = (float) $r->quantity;
            if ($qty == 0.0) {
                continue;
            }
            $events[] = $this->event($r->location_id, $r->product_id, $r->transaction_date, 'adjusted', -$qty, 0.0);
        }

        return $events;
    }

    /**
     * @return array{l: int, p: int, ts: int, day: string, k: string, d: float, r: float, w: float}
     */
    private function event($locationId, $productId, string $date, string $kind, float $delta, float $revenue): array
    {
        $ts = strtotime($date);

        return [
            'l' => (int) $locationId,
            'p' => (int) $productId,
            'ts' => $ts,
            'day' => substr($date, 0, 10),
            'k' => $kind,
            'd' => $delta,
            'r' => $revenue,
            'w' => 0.0,
        ];
    }

    /**
     * @param  list<int>|null  $productIds
     * @return array<string, float>
     */
    private function currentStock(?array $productIds): array
    {
        $locationIds = $this->scope->locationIds;

        return DB::table('variation_location_details as vld')
            ->join('products as p', 'p.id', '=', 'vld.product_id')
            ->where('p.business_id', $this->scope->businessId)
            ->where('p.enable_stock', 1)
            ->when($locationIds !== null, fn ($q) => $q->whereIn('vld.location_id', $locationIds === [] ? [0] : $locationIds))
            ->when($productIds !== null, fn ($q) => $q->whereIn('vld.product_id', $productIds === [] ? [0] : $productIds))
            ->groupBy('vld.location_id', 'vld.product_id')
            ->selectRaw('vld.location_id, vld.product_id, SUM(vld.qty_available) as qty')
            ->get()
            ->mapWithKeys(fn ($r) => [((int) $r->location_id).':'.((int) $r->product_id) => (float) $r->qty])
            ->all();
    }

    /**
     * Median time of the last sale of the day per location and weekday (hours since midnight).
     *
     * @param  list<array<string, mixed>>  $events
     * @return array<int, array<int, float>>
     */
    private function usualLastSale(array $events): array
    {
        $last = [];
        foreach ($events as $e) {
            if ($e['k'] !== 'sold') {
                continue;
            }
            $key = $e['l'].'|'.$e['day'];
            if (! isset($last[$key]) || $e['ts'] > $last[$key]) {
                $last[$key] = $e['ts'];
            }
        }
        $daytime = [];
        foreach ($events as $e) {
            if ($e['k'] !== 'sold') {
                continue;
            }
            $hour = (int) date('G', $e['ts']);
            $daytime[$e['l']][0] = ($daytime[$e['l']][0] ?? 0) + 1;
            if ($hour >= 6 && $hour < 17) {
                $daytime[$e['l']][1] = ($daytime[$e['l']][1] ?? 0) + 1;
            }
        }
        $this->liveEntry = [];
        foreach ($daytime as $locationId => $counts) {
            $this->liveEntry[(int) $locationId] = ($counts[1] ?? 0) / max(1, $counts[0]) >= 0.5;
        }

        $samples = [];
        foreach ($last as $key => $ts) {
            [$locationId, $day] = explode('|', $key);
            $weekday = (int) date('w', strtotime($day));
            $samples[(int) $locationId][$weekday][] = $this->hourOf($ts);
        }
        $out = [];
        foreach ($samples as $locationId => $byWeekday) {
            foreach ($byWeekday as $weekday => $hours) {
                $out[$locationId][$weekday] = Stats::quantile($hours, 0.5);
            }
        }

        return $out;
    }

    private function hourOf(int $ts): float
    {
        return (int) date('G', $ts) + ((int) date('i', $ts)) / 60;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRow(int $locationId, int $productId, float $opening): array
    {
        $row = [
            'location_id' => $locationId,
            'product_id' => $productId,
            'opening' => $opening,
        ];
        foreach (self::KINDS as $kind) {
            $row[$kind] = 0.0;
        }

        return $row + [
            'production_waste' => 0.0,
            'revenue' => 0.0,
            'closing' => $opening,
            'first_sale_ts' => null,
            'last_sale_ts' => null,
            'stock_after_last_sale' => null,
            '_out_after_sale' => 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function finishRow(array $row, int $weekday, string $day): array
    {
        $usual = $this->usualClose[$row['location_id']][$weekday] ?? null;
        $lastSaleHour = $row['last_sale_ts'] !== null ? $this->hourOf($row['last_sale_ts']) : null;
        $available = $row['opening'] + $row['produced'] + $row['purchased'] + $row['opening_stock'] + $row['transfer_in'];
        $live = $this->liveEntry[$row['location_id']] ?? false;
        if ($live) {
            $leftAfterTrading = $row['stock_after_last_sale'];
        } else {
            // Night-entry shops key sales and transfers in any order, so judge the whole day: nothing left and nothing sent back.
            $sentBack = $row['produced'] > 0 ? $row['_out_after_sale'] : $row['transfer_out'];
            $leftAfterTrading = $row['sold'] > 0 ? max(0.0, $row['closing']) + $sentBack : null;
        }
        $soldOut = $row['sold'] > 0
            && $leftAfterTrading !== null
            && $leftAfterTrading <= self::SOLD_OUT_THRESHOLD;
        $hoursEarly = ($soldOut && $live && $usual !== null && $lastSaleHour !== null) ? max(0.0, $usual - $lastSaleHour) : null;

        $row['date'] = $day;
        $row['available'] = $available;
        $row['returned_after_trading'] = $row['_out_after_sale'];
        $row['last_sale_time'] = $row['last_sale_ts'] !== null ? date('H:i', $row['last_sale_ts']) : null;
        $row['last_sale_hour'] = $lastSaleHour;
        $row['sold_out'] = $soldOut;
        $row['sold_out_hours_early'] = $hoursEarly !== null ? round($hoursEarly, 2) : null;
        $row['live_entry'] = $live;
        $row['negative_stock'] = $row['closing'] < -self::SOLD_OUT_THRESHOLD
            || ($row['stock_after_last_sale'] !== null && $row['stock_after_last_sale'] < -self::SOLD_OUT_THRESHOLD);
        unset($row['_out_after_sale']);

        return $row;
    }

    /**
     * @param  list<int>  $productIds
     */
    private function loadProducts(array $productIds): void
    {
        $ids = array_values(array_filter($productIds));
        if ($ids === []) {
            return;
        }
        $rows = DB::table('products as p')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $this->scope->businessId)
            ->whereIn('p.id', $ids)
            ->select(['p.id', 'p.name', 'u.short_name as unit', 'c.name as category'])
            ->get();
        foreach ($rows as $r) {
            $this->products[(int) $r->id] = [
                'product_id' => (int) $r->id,
                'name' => (string) $r->name,
                'unit' => (string) ($r->unit ?: 'unit'),
                'category' => $r->category !== null ? (string) $r->category : null,
            ];
        }
    }
}
