<?php

namespace Modules\AIBusinessManager\Services\Concerns;

use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AIBusinessManager\Services\Manager\ActionPlanner;
use Modules\AIBusinessManager\Services\Manager\BriefBuilder;
use Modules\AIBusinessManager\Services\Manager\CashControl;
use Modules\AIBusinessManager\Services\Manager\DataQuality;
use Modules\AIBusinessManager\Services\Manager\Economics;
use Modules\AIBusinessManager\Services\Manager\ForecastReport;
use Modules\AIBusinessManager\Services\Manager\ManagerScope;
use Modules\AIBusinessManager\Services\Manager\ManagerStore;
use Modules\AIBusinessManager\Services\Manager\ProductionReport;

/**
 * Business-manager tools: stock ledger, forecasting, economics, cash control, data quality, actions, brief.
 * All reads are limited to the business and the user's permitted locations. The only writes go to Eli's own
 * ai_bm_* tables (notes and tasks).
 */
trait ManagerTools
{
    /**
     * @return list<array<string, mixed>>
     */
    protected function managerToolDefinitions(): array
    {
        $range = [
            'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, business timezone.'],
            'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD inclusive.'],
        ];
        $location = [
            'location_id' => ['type' => 'integer'],
            'location_name' => ['type' => 'string', 'description' => 'Shop name (exact or substring).'],
        ];
        $tool = fn (string $name, string $description, array $properties = [], array $required = []) => [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required],
            ],
        ];

        return [
            $tool('production_ledger', 'Daily stock ledger for baked products from Manufacturing production, transfers, sales and stock adjustments: baked, received, sold, sent out/returned, written off, opening/closing, sell-through, sold-out days and sell-out time. Network view shows sold % of baked. Default last 7 days. Pass location + name_query for a day-by-day table.', $range + $location + [
                'name_query' => ['type' => 'string', 'description' => 'Product name substring, e.g. butter bread.'],
            ]),
            $tool('ingredient_variance', 'Ingredients: recipe-expected use (from units baked) vs recorded use on the production screen, purchases, write-offs, and production waste per product. Finds over-use and shrinkage. Default last 28 days.', $range),
            $tool('demand_forecast', 'Top-quality demand forecast per shop and product for a future day (default tomorrow) with likely range, recommended quantity to send (margin-based), and the bake plan per bakery (batches, where to send). Backtested model per series, sold-out correction, holidays, pay days, owner calendar events. days up to 7 for a multi-day outlook. Use for "how much to bake", "what to send to Trek", "forecast".', $location + [
                'target_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, default tomorrow.'],
                'days' => ['type' => 'integer', 'description' => '1–7 days starting at target_date.'],
                'name_query' => ['type' => 'string'],
                'leftover_value_pct' => ['type' => 'number', 'description' => 'What an unsold loaf is worth as % of price (default 25).'],
                'count_leftovers' => ['type' => 'boolean', 'description' => 'Subtract current shop stock (default: only after 18:00 for tomorrow).'],
            ]),
            $tool('forecast_accuracy', 'How accurate Eli\'s forecasts are: saved forecasts vs actual sales for past days, and backtest error per shop/product vs a naive same-day-last-week forecast.', $range),
            $tool('unit_economics', 'Full cost and margin per baked product: ingredients at latest prices, production and other overheads from expenses and owner fixed costs, cost per sold unit after unsold/written-off stock, profit, loss makers. Default last 28 days.', $range),
            $tool('location_profit', 'Management profit by shop: revenue, cost of goods, written off, distribution, people/admin and fixed costs allocated, profit and margin. Default this month to date.', $range),
            $tool('cashier_exceptions', 'Per cashier: discounts and below-list price cuts, deleted sales, returns, sale and payment edits, flagged vs team median. Default last 14 days.', $range),
            $tool('register_variances', 'Cash register sessions: expected vs counted cash, shortages/overages per cashier, registers left open. Default last 14 days.', $range),
            $tool('payment_reconciliation', 'Payments received per day, shop and method (Cash, MTN MoMo…) with counts, for matching against MoMo/bank statements; unpaid invoices. Default last 7 days.', $range + [
                'method' => ['type' => 'string', 'description' => 'Optional method, e.g. momo or cash.'],
            ]),
            $tool('staff_productivity', 'Per staff member: selling days, invoices, sales, average invoice, sales per day and per hour. Default last 28 days.', $range),
            $tool('data_quality_audit', 'Checks record-keeping problems (negative stock, open registers, adjustments without reasons, unfinished production, batch sales entry, missing costs…) with a score and fixes.', [
                'days' => ['type' => 'integer', 'description' => 'Look-back days, default 90.'],
            ]),
            $tool('purchase_suggestion', 'Ingredients to buy for the next N days from the forecast bake plan × recipes, minus stock, plus safety stock; last supplier and estimated cost. Suggestion only.', [
                'days' => ['type' => 'integer', 'description' => 'Default 7.'],
                'safety_days' => ['type' => 'number', 'description' => 'Default 2.'],
            ]),
            $tool('save_note', 'Save a decision or task in Eli\'s own notes (never changes TeamPOS records). Use when the owner decides something (price change, new product, more stock to a shop) or asks Eli to remember a to-do.', $location + [
                'kind' => ['type' => 'string', 'enum' => ['decision', 'task']],
                'title' => ['type' => 'string'],
                'details' => ['type' => 'string'],
                'name_query' => ['type' => 'string', 'description' => 'Product the decision is about.'],
                'effective_on' => ['type' => 'string', 'description' => 'YYYY-MM-DD when a decision takes effect.'],
                'due_on' => ['type' => 'string', 'description' => 'YYYY-MM-DD for tasks.'],
            ], ['kind', 'title']),
            $tool('list_notes', 'List saved decisions and tasks.', [
                'kind' => ['type' => 'string', 'enum' => ['decision', 'task']],
                'status' => ['type' => 'string', 'enum' => ['open', 'done']],
            ]),
            $tool('complete_task', 'Mark a saved task done.', ['note_id' => ['type' => 'integer']], ['note_id']),
            $tool('decision_impact', 'Did a decision work? Before vs after (same length) for units, revenue, price, leftovers and sold-out rate at a shop/product, with other shops as control. Pass note_id or effective_on (+ location, name_query).', $location + [
                'note_id' => ['type' => 'integer'],
                'effective_on' => ['type' => 'string'],
                'name_query' => ['type' => 'string'],
                'window_days' => ['type' => 'integer'],
            ]),
            $tool('target_progress', 'Progress against the owner\'s monthly targets (revenue, gross profit, invoices, waste %, sell-through %) with month-end projection and needed per day.', [
                'month' => ['type' => 'string', 'description' => 'YYYY-MM, default this month.'],
            ]),
            $tool('customer_account_health', 'Named customer accounts: last 90 vs prior 90 days, lapsed buyers, payment speed, outstanding balance, risk flags.'),
            $tool('daily_brief', 'Today\'s morning brief: yesterday vs normal per shop, today\'s plan, watch-outs. Set refresh true to rebuild.', [
                'refresh' => ['type' => 'boolean'],
            ]),
            $tool('alerts', 'Check live alerts now (slow trading at live-entry shops, early sell-outs, registers left open) and list alerts from the last 3 days.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null null when $name is not a manager tool
     */
    protected function executeManagerTool(string $name, array $args, int $businessId, User $user): ?array
    {
        if (! in_array($name, array_map(fn ($t) => $t['function']['name'], $this->managerToolDefinitions()), true)) {
            return null;
        }
        $scope = ManagerScope::forUser($user, $businessId);
        if (! $scope) {
            return ['ok' => false, 'error' => 'business_not_found'];
        }

        try {
            return match ($name) {
                'production_ledger' => (new ProductionReport($scope))->productionLedger($args, ...$this->managerRange($args, $scope, 7)),
                'ingredient_variance' => (new ProductionReport($scope))->ingredientVariance(...$this->managerRange($args, $scope, 28)),
                'demand_forecast' => (new ForecastReport($scope))->demandForecast($args),
                'forecast_accuracy' => (new ForecastReport($scope))->forecastAccuracy($args),
                'unit_economics' => (new Economics($scope))->unitEconomics(...$this->managerRange($args, $scope, 28)),
                'location_profit' => (new Economics($scope))->locationPnl(...$this->managerRange($args, $scope, 0)),
                'cashier_exceptions' => (new CashControl($scope))->cashierExceptions(...$this->managerRange($args, $scope, 14)),
                'register_variances' => (new CashControl($scope))->registerVariances(...$this->managerRange($args, $scope, 14)),
                'payment_reconciliation' => (new CashControl($scope))->paymentReconciliation(...[...$this->managerRange($args, $scope, 7), $args]),
                'staff_productivity' => (new CashControl($scope))->staffProductivity(...$this->managerRange($args, $scope, 28)),
                'data_quality_audit' => (new DataQuality($scope))->audit(max(7, min(365, (int) ($args['days'] ?? 90)))),
                'purchase_suggestion' => (new ActionPlanner($scope))->purchaseSuggestion((int) ($args['days'] ?? 7), (float) ($args['safety_days'] ?? 2)),
                'save_note' => $this->managerSaveNote($args, $scope, $user),
                'list_notes' => ['ok' => true, 'notes' => (new ManagerStore($businessId))->notes($args['kind'] ?? null, $args['status'] ?? null)],
                'complete_task' => ['ok' => (new ManagerStore($businessId))->completeNote((int) ($args['note_id'] ?? 0))],
                'decision_impact' => (new ActionPlanner($scope))->decisionImpact($args),
                'target_progress' => (new ActionPlanner($scope))->targetProgress(! empty($args['month']) ? Carbon::parse($args['month'].'-01', $scope->timezone) : $scope->today()),
                'customer_account_health' => (new ActionPlanner($scope))->customerAccountHealth(),
                'daily_brief' => ['ok' => true, 'brief' => (new BriefBuilder($scope))->briefFor($scope->today(), $scope->locationIds === null ? 'daily' : 'user:'.$user->id, (bool) ($args['refresh'] ?? false)), 'note' => 'Show the brief as written; it already contains the tables.'],
                'alerts' => [
                    'ok' => true,
                    'new' => (new BriefBuilder($scope))->checkAlerts(),
                    'recent' => array_map(fn ($a) => array_intersect_key($a, array_flip(['alert_date', 'severity', 'message'])), (new ManagerStore($businessId))->alerts($scope->today()->subDays(3))),
                ],
            };
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => 'tool_failed', 'details' => config('app.debug') ? $e->getMessage() : null];
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function managerRange(array $args, ManagerScope $scope, int $defaultDays): array
    {
        $today = $scope->today();
        try {
            $end = ! empty($args['end_date']) ? Carbon::parse((string) $args['end_date'], $scope->timezone)->startOfDay() : $today->copy();
            if (! empty($args['start_date'])) {
                $start = Carbon::parse((string) $args['start_date'], $scope->timezone)->startOfDay();
            } elseif ($defaultDays === 0) {
                $start = $end->copy()->startOfMonth();
            } else {
                $start = $end->copy()->subDays($defaultDays - 1);
            }
        } catch (\Throwable) {
            $end = $today->copy();
            $start = $today->copy()->subDays(max(1, $defaultDays) - 1);
        }
        if ($end->gt($today)) {
            $end = $today->copy();
        }
        if ($start->gt($end)) {
            [$start, $end] = [$end->copy(), $start->copy()];
        }

        return [$start, $end];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function managerSaveNote(array $args, ManagerScope $scope, User $user): array
    {
        $kind = in_array($args['kind'] ?? '', ['decision', 'task'], true) ? $args['kind'] : 'task';
        $title = trim((string) ($args['title'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'error' => 'title_required'];
        }
        $locationId = null;
        if (! empty($args['location_id']) || ! empty($args['location_name'])) {
            $resolved = $scope->resolveLocationArgs($args);
            if (isset($resolved['error'])) {
                return $resolved['error'];
            }
            $locationId = $resolved['scope']->locationIds[0] ?? null;
        }
        $productId = null;
        if (! empty($args['name_query'])) {
            $productId = DB::table('products')->where('business_id', $scope->businessId)->where('name', 'like', '%'.trim((string) $args['name_query']).'%')->orderByRaw('LENGTH(name)')->value('id');
        }
        $date = function ($value) use ($scope) {
            try {
                return $value ? Carbon::parse((string) $value, $scope->timezone)->toDateString() : null;
            } catch (\Throwable) {
                return null;
            }
        };
        $id = (new ManagerStore($scope->businessId))->addNote([
            'kind' => $kind,
            'title' => $title,
            'details' => $args['details'] ?? null,
            'location_id' => $locationId,
            'product_id' => $productId ? (int) $productId : null,
            'effective_on' => $date($args['effective_on'] ?? null) ?? ($kind === 'decision' ? $scope->today()->toDateString() : null),
            'due_on' => $date($args['due_on'] ?? null),
        ], (int) $user->id);

        return $id ? ['ok' => true, 'note_id' => $id, 'kind' => $kind, 'note' => 'Saved in Eli notes. Use decision_impact with this note_id later to measure the effect.'] : ['ok' => false, 'error' => 'storage_unavailable'];
    }
}
