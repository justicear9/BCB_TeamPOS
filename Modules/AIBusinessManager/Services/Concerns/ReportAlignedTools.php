<?php

namespace Modules\AIBusinessManager\Services\Concerns;

use App\Business;
use App\User;
use App\Utils\ModuleUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only helpers aligned with core ReportController / ProductUtil / TransactionUtil.
 * Relies on auth()->user() inside upstream utilities for permitted_locations where those utilities do not accept User.
 */
trait ReportAlignedTools
{
    /**
     * @return array{ok: true, location_id: ?int}|array{ok: false, error: string}
     */
    protected function validateOptionalLocationForUser(?int $locationId, User $user): array
    {
        if ($locationId === null || $locationId <= 0) {
            return ['ok' => true, 'location_id' => null];
        }

        $permitted = $user->permitted_locations();
        if ($permitted !== 'all' && (! is_array($permitted) || ! in_array($locationId, $permitted, true))) {
            return ['ok' => false, 'error' => 'location_not_permitted'];
        }

        return ['ok' => true, 'location_id' => $locationId];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function purchaseSellTotals(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }

        $locCheck = $this->validateOptionalLocationForUser(
            isset($args['location_id']) ? (int) $args['location_id'] : null,
            $user
        );
        if ($locCheck['ok'] === false) {
            return $locCheck;
        }

        $location_id = $locCheck['location_id'];
        $permitted = $user->permitted_locations();

        /** @var TransactionUtil $txn */
        $txn = app(TransactionUtil::class);

        $start = $range['start']->format('Y-m-d');
        $end = $range['end']->format('Y-m-d');

        $purchase_details = $txn->getPurchaseTotals($businessId, $start, $end, $location_id, null, $permitted);
        $sell_details = $txn->getSellTotals($businessId, $start, $end, $location_id, null, $permitted);

        $transaction_totals = $txn->getTransactionTotals(
            $businessId,
            ['purchase_return', 'sell_return'],
            $start,
            $end,
            $location_id,
            null,
            $permitted
        );

        $total_purchase_return_inc_tax = (float) ($transaction_totals['total_purchase_return_inc_tax'] ?? 0);
        $total_sell_return_inc_tax = (float) ($transaction_totals['total_sell_return_inc_tax'] ?? 0);

        $sell_inc = (float) ($sell_details['total_sell_inc_tax'] ?? 0);
        $purchase_inc = (float) ($purchase_details['total_purchase_inc_tax'] ?? 0);

        $difference = [
            'total' => $sell_inc - $total_sell_return_inc_tax - ($purchase_inc - $total_purchase_return_inc_tax),
            'due' => (float) ($sell_details['invoice_due'] ?? 0) - (float) ($purchase_details['purchase_due'] ?? 0),
        ];

        $precision = $range['precision'];

        return [
            'ok' => true,
            'basis' => 'purchase_n_sell_report',
            'currency_code' => $range['code'],
            'currency_symbol' => $range['symbol'],
            'start_date' => $start,
            'end_date' => $end,
            'purchase' => [
                'total_purchase_inc_tax' => round((float) ($purchase_details['total_purchase_inc_tax'] ?? 0), $precision),
                'total_purchase_exc_tax' => round((float) ($purchase_details['total_purchase_exc_tax'] ?? 0), $precision),
                'purchase_due' => round((float) ($purchase_details['purchase_due'] ?? 0), $precision),
                'total_shipping_charges' => round((float) ($purchase_details['total_shipping_charges'] ?? 0), $precision),
                'total_additional_expense' => round((float) ($purchase_details['total_additional_expense'] ?? 0), $precision),
            ],
            'sell' => [
                'total_sell_inc_tax' => round($sell_inc, $precision),
                'total_sell_exc_tax' => round((float) ($sell_details['total_sell_exc_tax'] ?? 0), $precision),
                'invoice_due' => round((float) ($sell_details['invoice_due'] ?? 0), $precision),
                'total_shipping_charges' => round((float) ($sell_details['total_shipping_charges'] ?? 0), $precision),
                'total_additional_expense' => round((float) ($sell_details['total_additional_expense'] ?? 0), $precision),
            ],
            'total_purchase_return_inc_tax' => round($total_purchase_return_inc_tax, $precision),
            'total_sell_return_inc_tax' => round($total_sell_return_inc_tax, $precision),
            'difference' => [
                'sell_minus_net_purchase_inc_tax' => round((float) $difference['total'], $precision),
                'invoice_due_minus_purchase_due' => round((float) $difference['due'], $precision),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function profitLossSnapshot(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }

        $locCheck = $this->validateOptionalLocationForUser(
            isset($args['location_id']) ? (int) $args['location_id'] : null,
            $user
        );
        if ($locCheck['ok'] === false) {
            return $locCheck;
        }

        $location_id = $locCheck['location_id'];
        $user_id = isset($args['user_id']) ? (int) $args['user_id'] : null;
        if ($user_id !== null && $user_id <= 0) {
            $user_id = null;
        }

        $permitted = $user->permitted_locations();
        $start = $range['start']->format('Y-m-d');
        $end = $range['end']->format('Y-m-d');
        $precision = $range['precision'];

        /** @var TransactionUtil $txn */
        $txn = app(TransactionUtil::class);
        $raw = $txn->getProfitLossDetails($businessId, $location_id, $start, $end, $user_id, $permitted);

        $numeric_keys = [
            'opening_stock', 'closing_stock', 'total_purchase', 'total_purchase_discount', 'total_purchase_return',
            'total_sell', 'total_sell_discount', 'total_sell_return_discount', 'total_sell_return', 'total_sell_round_off',
            'total_expense', 'total_adjustment', 'total_recovered', 'total_reward_amount',
            'total_transfer_shipping_charges', 'total_purchase_shipping_charge', 'total_sell_shipping_charge',
            'total_purchase_additional_expense', 'total_sell_additional_expense',
            'gross_profit', 'net_profit',
        ];

        $out = [
            'ok' => true,
            'basis' => 'profit_loss_report',
            'currency_code' => $range['code'],
            'currency_symbol' => $range['symbol'],
            'start_date' => $start,
            'end_date' => $end,
            'caveat' => 'Includes optional module hooks (profitLossReportData / grossProfit) like the web report; subscriber-only manufacturing rows are omitted unless enabled for the session user.',
        ];

        foreach ($numeric_keys as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = round((float) $raw[$key], $precision);
            }
        }

        $out['gross_profit_label'] = $raw['gross_profit_label'] ?? [];

        $out['total_sell_by_subtype'] = collect($raw['total_sell_by_subtype'] ?? [])->map(function ($row) use ($precision) {
            return [
                'sub_type' => (string) ($row->sub_type ?? ''),
                'total_before_tax' => round((float) ($row->total_before_tax ?? 0), $precision),
            ];
        })->values()->all();

        $out['left_side_module_lines'] = collect($raw['left_side_module_data'] ?? [])->map(function ($row) use ($precision) {
            return [
                'label' => (string) ($row['label'] ?? ''),
                'value' => isset($row['value']) ? round((float) $row['value'], $precision) : null,
                'add_to_net_profit' => ! empty($row['add_to_net_profit']),
            ];
        })->values()->all();

        $out['right_side_module_lines'] = collect($raw['right_side_module_data'] ?? [])->map(function ($row) use ($precision) {
            return [
                'label' => (string) ($row['label'] ?? ''),
                'value' => isset($row['value']) ? round((float) $row['value'], $precision) : null,
                'add_to_net_profit' => ! empty($row['add_to_net_profit']),
            ];
        })->values()->all();

        return $out;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function taxReportSnapshot(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }

        $locCheck = $this->validateOptionalLocationForUser(
            isset($args['location_id']) ? (int) $args['location_id'] : null,
            $user
        );
        if ($locCheck['ok'] === false) {
            return $locCheck;
        }

        $contact_id = isset($args['contact_id']) ? (int) $args['contact_id'] : null;
        if ($contact_id !== null && $contact_id <= 0) {
            $contact_id = null;
        }

        $start = $range['start']->format('Y-m-d');
        $end = $range['end']->format('Y-m-d');
        $location_id = $locCheck['location_id'];
        $precision = $range['precision'];

        /** @var TransactionUtil $txn */
        $txn = app(TransactionUtil::class);
        /** @var ModuleUtil $moduleUtil */
        $moduleUtil = app(ModuleUtil::class);

        $input_tax_details = $txn->getInputTax($businessId, $start, $end, $location_id, $contact_id);
        $output_tax_details = $txn->getOutputTax($businessId, $start, $end, $location_id, $contact_id);
        $expense_tax_details = $txn->getExpenseTax($businessId, $start, $end, $location_id, $contact_id);

        $module_output_taxes = $moduleUtil->getModuleData('getModuleOutputTax', ['start_date' => $start, 'end_date' => $end]);
        $total_module_output_tax = 0.0;
        foreach ($module_output_taxes as $val) {
            $total_module_output_tax += (float) $val;
        }

        $total_output_tax = (float) ($output_tax_details['total_tax'] ?? 0) + $total_module_output_tax;
        $total_input_tax = (float) ($input_tax_details['total_tax'] ?? 0);
        $total_expense_tax = (float) ($expense_tax_details['total_tax'] ?? 0);
        $tax_diff = $total_output_tax - $total_input_tax - $total_expense_tax;

        $flattenTax = function (array $details) use ($precision): array {
            return collect($details)->map(function ($row, $tax_id) use ($precision) {
                return [
                    'tax_id' => (int) $tax_id,
                    'tax_name' => (string) ($row['tax_name'] ?? ''),
                    'tax_amount' => round((float) ($row['tax_amount'] ?? 0), $precision),
                ];
            })->values()->all();
        };

        return [
            'ok' => true,
            'basis' => 'tax_report',
            'currency_code' => $range['code'],
            'currency_symbol' => $range['symbol'],
            'start_date' => $start,
            'end_date' => $end,
            'total_output_tax' => round($total_output_tax, $precision),
            'total_input_tax' => round($total_input_tax, $precision),
            'total_expense_tax' => round($total_expense_tax, $precision),
            'total_module_output_tax' => round($total_module_output_tax, $precision),
            'tax_diff_output_minus_input_minus_expense' => round((float) $tax_diff, $precision),
            'output_tax_lines' => $flattenTax($output_tax_details['tax_details'] ?? []),
            'input_tax_lines' => $flattenTax($input_tax_details['tax_details'] ?? []),
            'expense_tax_lines' => $flattenTax($expense_tax_details['tax_details'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function stockReportRows(array $args, int $businessId, User $user): array
    {
        $business = Business::with('currency')->find($businessId);
        if (! $business) {
            return ['ok' => false, 'error' => 'business_not_found'];
        }

        $precision = (int) ($business->currency_precision ?? 2);

        $locCheck = $this->validateOptionalLocationForUser(
            isset($args['location_id']) ? (int) $args['location_id'] : null,
            $user
        );
        if ($locCheck['ok'] === false) {
            return $locCheck;
        }

        $limit = isset($args['limit']) ? (int) $args['limit'] : 40;
        $limit = max(1, min(80, $limit));

        $filters = [
            'not_for_selling' => 0,
            'show_manufacturing_data' => 0,
            'active_state' => 'active',
        ];

        if ($locCheck['location_id'] !== null) {
            $filters['location_id'] = $locCheck['location_id'];
        }
        foreach (['category_id', 'sub_category_id', 'brand_id', 'unit_id'] as $key) {
            if (! empty($args[$key])) {
                $filters[$key] = (int) $args[$key];
            }
        }

        /** @var ProductUtil $productUtil */
        $productUtil = app(ProductUtil::class);

        $query = $productUtil->getProductStockDetails($businessId, $filters, 'datatables');

        $rows = $query
            ->orderByDesc(DB::raw('SUM(vld.qty_available)'))
            ->limit($limit)
            ->get();

        return [
            'ok' => true,
            'basis' => 'stock_report',
            'currency_symbol' => $business->currency?->symbol ?? '',
            'rows' => $rows->map(function ($r) use ($precision) {
                $variation = '';
                if (($r->type ?? '') === 'variable') {
                    $variation = trim(($r->product_variation ?? '').'-'.($r->variation_name ?? ''), '-');
                }

                $stock = (float) ($r->stock ?? 0);
                $unit_price = (float) ($r->unit_price ?? 0);
                $stock_price = (float) ($r->stock_price ?? 0);
                $potential_profit = ($stock * $unit_price) - $stock_price;

                return [
                    'product' => (string) ($r->product ?? ''),
                    'sku' => (string) ($r->sku ?? ''),
                    'variation' => $variation,
                    'location' => (string) ($r->location_name ?? ''),
                    'stock' => $this->roundQuantity($stock),
                    'unit' => (string) ($r->unit ?? ''),
                    'unit_selling_price_inc_tax' => round($unit_price, $precision),
                    'stock_value_at_purchase_price' => round($stock_price, $precision),
                    'approx_potential_profit_at_default_sell_price' => round($potential_profit, $precision),
                    'total_sold' => $this->roundQuantity((float) ($r->total_sold ?? 0)),
                    'total_transferred' => $this->roundQuantity((float) ($r->total_transfered ?? 0)),
                    'total_adjusted' => $this->roundQuantity((float) ($r->total_adjusted ?? 0)),
                    'alert_quantity' => isset($r->alert_quantity) ? $this->roundQuantity((float) $r->alert_quantity) : null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function stockValueSnapshot(array $args, int $businessId, User $user): array
    {
        $business = Business::with('currency')->find($businessId);
        if (! $business) {
            return ['ok' => false, 'error' => 'business_not_found'];
        }

        $precision = (int) ($business->currency_precision ?? 2);

        $locCheck = $this->validateOptionalLocationForUser(
            isset($args['location_id']) ? (int) $args['location_id'] : null,
            $user
        );
        if ($locCheck['ok'] === false) {
            return $locCheck;
        }

        $tz = is_string($business->time_zone) && trim($business->time_zone) !== ''
            ? trim($business->time_zone)
            : (string) config('app.timezone');

        $as_at_raw = isset($args['as_at_date']) ? (string) $args['as_at_date'] : '';
        try {
            $as_at = $as_at_raw !== ''
                ? Carbon::parse($as_at_raw, $tz)->format('Y-m-d')
                : Carbon::now($tz)->format('Y-m-d');
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'invalid_as_at_date'];
        }

        $filters = [];
        foreach (['category_id', 'sub_category_id', 'brand_id', 'unit_id'] as $key) {
            if (! empty($args[$key])) {
                $filters[$key] = (int) $args[$key];
            }
        }

        $permitted = $user->permitted_locations();
        /** @var TransactionUtil $txn */
        $txn = app(TransactionUtil::class);

        $closing_stock_by_pp = $txn->getOpeningClosingStock(
            $businessId,
            $as_at,
            $locCheck['location_id'],
            false,
            false,
            $filters,
            $permitted
        );
        $closing_stock_by_sp = $txn->getOpeningClosingStock(
            $businessId,
            $as_at,
            $locCheck['location_id'],
            false,
            true,
            $filters,
            $permitted
        );

        $closing_stock_by_pp = (float) $closing_stock_by_pp;
        $closing_stock_by_sp = (float) $closing_stock_by_sp;
        $potential_profit = $closing_stock_by_sp - $closing_stock_by_pp;
        $profit_margin = $closing_stock_by_sp == 0.0 ? 0.0 : ($potential_profit / $closing_stock_by_sp) * 100;

        return [
            'ok' => true,
            'basis' => 'stock_value_report',
            'currency_code' => $business->currency?->code ?? '',
            'currency_symbol' => $business->currency?->symbol ?? '',
            'as_at_date' => $as_at,
            'closing_stock_value_by_purchase_price' => round($closing_stock_by_pp, $precision),
            'closing_stock_value_by_sale_price' => round($closing_stock_by_sp, $precision),
            'potential_profit_if_all_sold_at_default_sale_price' => round($potential_profit, $precision),
            'potential_margin_percent_of_sale_value' => round($profit_margin, $precision),
        ];
    }

    /**
     * Trending products by base sell-line quantity (matches Trending Products report — not invoice sub-unit UoM).
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function trendingProductsUnits(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }

        $locCheck = $this->validateOptionalLocationForUser(
            isset($args['location_id']) ? (int) $args['location_id'] : null,
            $user
        );
        if ($locCheck['ok'] === false) {
            return $locCheck;
        }

        $limit = isset($args['limit']) ? (int) $args['limit'] : 15;
        $limit = max(1, min(50, $limit));

        $filters = [
            'start_date' => $range['start']->format('Y-m-d'),
            'end_date' => $range['end']->format('Y-m-d'),
            'limit' => $limit,
        ];

        if ($locCheck['location_id'] !== null) {
            $filters['location_id'] = $locCheck['location_id'];
        }
        foreach (['category', 'sub_category', 'brand', 'unit', 'product_type'] as $key) {
            if (! empty($args[$key])) {
                $filters[$key] = $args[$key];
            }
        }

        /** @var ProductUtil $productUtil */
        $productUtil = app(ProductUtil::class);
        $products = $productUtil->getTrendingProducts($businessId, $filters);

        return [
            'ok' => true,
            'basis' => 'trending_products_report',
            'caveat' => 'Quantities are summed from sell lines in product base units (TransactionSellLine quantity − returned), not POS invoice sub-units — compare to top_products when the merchant cares about selling UoM.',
            'start_date' => $filters['start_date'],
            'end_date' => $filters['end_date'],
            'rows' => collect($products)->map(function ($r) {
                return [
                    'product' => (string) ($r->product ?? ''),
                    'sku' => (string) ($r->sku ?? ''),
                    'unit_short_name' => (string) ($r->unit ?? ''),
                    'total_unit_sold_base_uom' => $this->roundQuantity((float) ($r->total_unit_sold ?? 0)),
                ];
            })->values()->all(),
        ];
    }
}
