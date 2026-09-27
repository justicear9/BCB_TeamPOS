<?php

namespace Modules\AIBusinessManager\Services;

use App\Business;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AIBusinessManager\Services\Concerns\AccountingSnapshotTools;
use Modules\AIBusinessManager\Services\Concerns\AggregatesTransactions;
use Modules\AIBusinessManager\Services\Concerns\ContactAndCatalogTools;
use Modules\AIBusinessManager\Services\Concerns\ExtendedBusinessDataTools;
use Modules\AIBusinessManager\Services\Concerns\InventoryIntelligenceTools;
use Modules\AIBusinessManager\Services\Concerns\ReportAlignedTools;
use Modules\AIBusinessManager\Services\Concerns\TransactionAndAnalyticsTools;

class BusinessDataToolService
{
    use AccountingSnapshotTools;
    use AggregatesTransactions;
    use ContactAndCatalogTools;
    use ExtendedBusinessDataTools;
    use InventoryIntelligenceTools;
    use ReportAlignedTools;
    use TransactionAndAnalyticsTools;

    public function __construct(
        protected BusinessInsightContextService $context
    ) {
    }

    /**
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    public function getOpenAiToolDefinitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'sales_aggregate',
                    'description' => 'Aggregate final sell invoices: revenue, invoice count, and quantity_selling_uom (sum of net qty in each line\'s invoice unit — TeamPOS sub_unit / base multiplier, not raw DB base units). Month/year buckets include the same qty field.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string', 'description' => 'Inclusive start date YYYY-MM-DD (business timezone).'],
                            'end_date' => ['type' => 'string', 'description' => 'Inclusive end date YYYY-MM-DD.'],
                            'granularity' => ['type' => 'string', 'enum' => ['total', 'month', 'year']],
                        ],
                        'required' => ['start_date', 'end_date', 'granularity'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'top_products',
                    'description' => 'Top products (main lines only). quantity_selling_uom sums net qty in the unit used on each invoice (POS sub-unit). selling_unit_labels lists distinct unit short names seen for that product in the range (same SKU may use multiple UoMs). sort_by "quantity" ranks by that qty; "revenue" by line value.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'limit' => ['type' => 'integer', 'description' => 'Max rows 1–25, default 10.'],
                            'sort_by' => ['type' => 'string', 'enum' => ['revenue', 'quantity'], 'description' => 'Rank by line revenue or by quantity_selling_uom (invoice UoM).'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'product_sales_trend',
                    'description' => 'Sales trend for ONE product over time: revenue, quantity_selling_uom (invoice UoM), and invoice count per day, ISO week, or calendar month. Match by product_id, OR by name_query (substring match on product name, variation name, or variation sub_sku). If multiple products match name_query, returns ambiguous matches — call again with product_id. Excludes modifier child lines (same as top_products).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'granularity' => ['type' => 'string', 'enum' => ['day', 'week', 'month'], 'description' => 'day: one row per calendar day (max 200-day span). week: ISO year-week label. month: YYYY-MM.'],
                            'product_id' => ['type' => 'integer', 'description' => 'Optional TeamPOS products.id — use when name_query is ambiguous or known.'],
                            'name_query' => ['type' => 'string', 'description' => 'Substring to match product/variation name or sub_sku (min 2 chars). Ignored if product_id is set.'],
                            'location_id' => ['type' => 'integer', 'description' => 'Optional single business_location id; must be permitted for this user.'],
                        ],
                        'required' => ['start_date', 'end_date', 'granularity'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'top_categories',
                    'description' => 'Top categories (main sell lines). quantity_selling_uom is summed in invoice line units (TeamPOS sub_unit). sort_by "quantity" or "revenue".',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'limit' => ['type' => 'integer', 'description' => 'Max rows 1–20, default 10.'],
                            'sort_by' => ['type' => 'string', 'enum' => ['revenue', 'quantity']],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'revenue_by_location',
                    'description' => 'Per location: revenue, invoice count, and quantity_selling_uom (net qty summed in each line\'s invoice unit, TeamPOS-style).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'purchase_aggregate',
                    'description' => 'Aggregate completed purchases (TeamPOS status=received, type=purchase): spend totals and bill count. Same date granularity options as sales_aggregate.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'granularity' => ['type' => 'string', 'enum' => ['total', 'month', 'year']],
                        ],
                        'required' => ['start_date', 'end_date', 'granularity'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'expense_aggregate',
                    'description' => 'Net operating expenses in range: sums expense final_totals minus expense_refund amounts (TeamPOS types expense / expense_refund, status=final). Month/year buckets supported.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'granularity' => ['type' => 'string', 'enum' => ['total', 'month', 'year']],
                        ],
                        'required' => ['start_date', 'end_date', 'granularity'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'top_customers',
                    'description' => 'Customers ranked by finalized sell revenue (contacts type customer/both). Each row: display name, revenue, invoice count.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'limit' => ['type' => 'integer', 'description' => 'Max 1–25, default 10.'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'top_suppliers',
                    'description' => 'Suppliers ranked by completed purchase spend (type purchase, status received; contacts type supplier/both).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'limit' => ['type' => 'integer', 'description' => 'Max 1–25, default 10.'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'stock_by_variation',
                    'description' => 'Current stock snapshot (not historical): SUM(qty_available) per product variation across permitted locations from variation_location_details. Only stock-tracked active products. sort_by low_qty surfaces tight inventory; high_qty surfaces largest positions.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'sort_by' => ['type' => 'string', 'enum' => ['low_qty', 'high_qty']],
                            'limit' => ['type' => 'integer', 'description' => 'Max 1–50, default 25.'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'stock_expiry_near',
                    'description' => 'Purchase-line batches with remaining stock and an expiry date, aligned with TeamPOS Stock Expiry Report (variation + expiry + lot aggregation). Uses business timezone for “today”. Rows sorted soonest exp_date first. Requires business setting product expiry enabled.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'within_days' => ['type' => 'integer', 'description' => 'Include batches expiring on or before today + this many days (1–730, default 90). Ignored for already-expired rows when include_expired is true; those match any expiry date in the past.'],
                            'include_expired' => ['type' => 'boolean', 'description' => 'If true, also include batches past expiry date that still have stock_left > 0 (still capped by limit).'],
                            'location_id' => ['type' => 'integer', 'description' => 'Optional single business_location id; must be permitted for this user.'],
                            'limit' => ['type' => 'integer', 'description' => 'Max rows 1–50, default 25.'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'sell_return_aggregate',
                    'description' => 'Finalized customer sell returns (type=sell_return, status=final): sum return totals and counts by total/month/year.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'granularity' => ['type' => 'string', 'enum' => ['total', 'month', 'year']],
                        ],
                        'required' => ['start_date', 'end_date', 'granularity'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'purchase_return_aggregate',
                    'description' => 'Finalized supplier purchase returns (type=purchase_return, status=final): totals and counts by period.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'granularity' => ['type' => 'string', 'enum' => ['total', 'month', 'year']],
                        ],
                        'required' => ['start_date', 'end_date', 'granularity'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'opening_stock_aggregate',
                    'description' => 'Opening stock entries (type=opening_stock, status=received): value (final_total) plus quantity from purchase_lines, by period.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'granularity' => ['type' => 'string', 'enum' => ['total', 'month', 'year']],
                        ],
                        'required' => ['start_date', 'end_date', 'granularity'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'sale_payment_mix',
                    'description' => 'Payments linked to finalized sell invoices only (transaction_payments.transaction_id NOT NULL join transactions). Amounts grouped by payment method. Date filter uses COALESCE(paid_on, created_at). Excludes standalone contact payments (no invoice link).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'stock_adjustment_aggregate',
                    'description' => 'Stock adjustments finalized as received: sums line quantities split increase vs decrease (adjustment_direction; defaults decrease when null). Optional granularity by adjustment transaction_date.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'granularity' => ['type' => 'string', 'enum' => ['total', 'month', 'year']],
                        ],
                        'required' => ['start_date', 'end_date', 'granularity'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'stock_transfer_summary',
                    'description' => 'Completed inter-location transfers (sell_transfer + paired purchase_transfer, status=final): counts, total base qty from outgoing sell lines, and top routes by volume.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'route_limit' => ['type' => 'integer', 'description' => 'Max route rows 1–25, default 10.'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'sales_order_pipeline',
                    'description' => 'Sales orders in date range: count and total final_total grouped by status (ordered/partial/completed etc.).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'purchase_order_pipeline',
                    'description' => 'Purchase orders in date range: count and value grouped by transaction status.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'payroll_aggregate',
                    'description' => 'Payroll transactions (type=payroll, status=final): totals by period.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'granularity' => ['type' => 'string', 'enum' => ['total', 'month', 'year']],
                        ],
                        'required' => ['start_date', 'end_date', 'granularity'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'purchase_sell_totals',
                    'description' => 'Purchase & Sale report summary for a date range: purchase totals (inc/exc tax, due), finalized sell totals, purchase_return and sell_return inc-tax totals, and the same “difference” figures as the TeamPOS Purchase & Sale report.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer', 'description' => 'Optional; must be permitted.'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'profit_loss_snapshot',
                    'description' => 'Profit / Loss report totals for a range (opening/closing stock at cost, sales & purchases exc tax, discounts, expenses, adjustments, gross profit, net profit, sales by sell sub_type, module add-ons). Same core calculation path as the web P&L.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer'],
                            'user_id' => ['type' => 'integer', 'description' => 'Optional filter: transactions.created_by (same as report filter when used).'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'tax_report_snapshot',
                    'description' => 'Tax report headline figures: output tax (sales less returns), input tax (purchases less returns), expense tax, optional module output taxes, and tax_diff like the Tax Report summary.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer'],
                            'contact_id' => ['type' => 'integer', 'description' => 'Optional contact filter (same as report).'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'stock_report_rows',
                    'description' => 'Stock Report–style rows per variation × location: qty on hand, default selling price (inc tax), stock value at purchase cost, sold/transferred/adjusted lifetime totals as on that report, optional filters.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'location_id' => ['type' => 'integer'],
                            'category_id' => ['type' => 'integer'],
                            'sub_category_id' => ['type' => 'integer'],
                            'brand_id' => ['type' => 'integer'],
                            'unit_id' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer', 'description' => 'Max rows 1–80, default 40. Ordered by highest quantity first.'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'stock_value_snapshot',
                    'description' => 'Stock valuation summary (Stock Value report): closing inventory at purchase cost vs default sale price as-of date, potential profit and margin %.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'as_at_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD in business timezone; default today.'],
                            'location_id' => ['type' => 'integer'],
                            'category_id' => ['type' => 'integer'],
                            'sub_category_id' => ['type' => 'integer'],
                            'brand_id' => ['type' => 'integer'],
                            'unit_id' => ['type' => 'integer'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'trending_products_report',
                    'description' => 'Trending Products report: top SKUs by total units sold in range (sell-line base quantity, not POS invoice sub-unit). Optional category/brand/unit/product_type filters.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer', 'description' => '1–50, default 15.'],
                            'category' => ['type' => 'integer', 'description' => 'category_id'],
                            'sub_category' => ['type' => 'integer'],
                            'brand' => ['type' => 'integer'],
                            'unit' => ['type' => 'integer'],
                            'product_type' => ['type' => 'string'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'contact_search',
                    'description' => 'Substring search on customers/suppliers (name, supplier_business_name, email, mobile, contact_id). Returns contact_id, display_name, type. Row cap server-side.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Min 2 characters.'],
                            'contact_type' => ['type' => 'string', 'enum' => ['any', 'customer', 'supplier', 'both']],
                            'limit' => ['type' => 'integer'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'contact_outstanding',
                    'description' => 'TeamPOS-style outstanding totals for one contact_id: sell invoices vs payments, purchase side, opening balance, returns, ledger discount — aligned with contact list math, not statutory Accounting GL.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'contact_id' => ['type' => 'integer'],
                        ],
                        'required' => ['contact_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'receivables_ageing',
                    'description' => 'POS-style open customer invoices (balance > ε): bucket totals by age from invoice transaction_date; capped sample rows. Prefer Accounting `accounting_ar_ageing_summary` when statutory due-date buckets are needed.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'location_id' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer', 'description' => 'Max sample invoices returned.'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'payables_ageing',
                    'description' => 'POS-style open supplier purchases (balance > ε): bucket totals from invoice date; capped samples. Prefer `accounting_ap_ageing_summary` for Accounting-module due-date ageing.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'location_id' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'product_search',
                    'description' => 'Catalog search on products/variations (name, sub_sku, barcode if column exists). Optional location_id appends qty_available from variation_location_details.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'transaction_detail',
                    'description' => 'Read-only drill-down: sell or purchase by transaction_id and/or invoice_no (+ type hint). Header, capped lines, payments.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'transaction_id' => ['type' => 'integer'],
                            'invoice_no' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => ['sell', 'purchase', '']],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'slow_moving_stock',
                    'description' => 'Variations with on-hand above threshold but low sell qty in range vs business average heuristic.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'product_margin_snapshot',
                    'description' => 'Top variations in range: line revenue vs purchase-line cost heuristic (see caveat vs stock_report_rows).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'lot_sell_trace',
                    'description' => 'From purchase line lot_number (or identifiers in args): consuming sell lines and transaction refs via FIFO link table.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'lot_number' => ['type' => 'string'],
                            'purchase_line_id' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'reorder_cover_hint',
                    'description' => 'Heuristic cover days: on-hand qty_available ÷ avg daily quantity sold (invoice UoM) over date range for one product_id or name_query.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'product_id' => ['type' => 'integer'],
                            'name_query' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'sales_by_weekday',
                    'description' => 'Final sells ranked by weekday revenue (highest first). Each row has day_name, revenue, invoices, quantity, share_of_revenue, and busiest_hour. Use this — not hour rows — whenever the merchant asks which day sells the most or for a day-of-week ranking. Present rows in rank order and use day_name exactly.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'sales_by_hour_weekday',
                    'description' => 'Final sells by clock hour and weekday. Includes weekday_ranking (revenue order, with day_name). Use the ranking for which day earns the most. Use hour rows only to describe busy times of day. Do not sum hour rows or rename days.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'basket_metrics',
                    'description' => 'Per final sell: average ticket, avg lines per invoice. granularity `range` aggregates whole span; `day` returns one row per calendar day.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer'],
                            'granularity' => ['type' => 'string', 'enum' => ['range', 'day']],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'sales_by_cashier',
                    'description' => 'Group finalized sells by created_by user: revenue and invoice count. May be restricted to own sales per business permission.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'discount_summary',
                    'description' => 'Sums line_discount_amount on sell lines and transaction-level discount_amount when columns exist; else unsupported.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'accounting_trial_balance_summary',
                    'description' => 'When Accounting module is installed and user may view reports: debit/credit totals per account in date range (capped rows) plus grand totals. Not a substitute for the full TB export.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'accounting_ar_ageing_summary',
                    'description' => 'Accounting-module receivable ageing (due-date buckets) with top contacts — same engine as Accounting AR report.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'location_id' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer', 'description' => 'Top contacts by due.'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'accounting_ap_ageing_summary',
                    'description' => 'Accounting-module payable ageing summary (due-date buckets).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'location_id' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'accounting_balance_sheet_headlines',
                    'description' => 'Posted GL section totals (assets, liabilities, equity) for a date range — headlines only.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'accounting_cash_flow_headlines',
                    'description' => 'Direct-style cash movement on flagged cash/bank accounts: net total and top accounts by absolute net.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                            'location_id' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
            ],
        ];
    }

    public function execute(string $name, string $argumentsJson, int $businessId, User $user): string
    {
        $args = json_decode($argumentsJson, true);
        if (! is_array($args)) {
            return json_encode(['ok' => false, 'error' => 'invalid_json_arguments']);
        }

        return match ($name) {
            'sales_aggregate' => json_encode($this->salesAggregate($args, $businessId, $user)),
            'top_products' => json_encode($this->topProducts($args, $businessId, $user)),
            'product_sales_trend' => json_encode($this->productSalesTrend($args, $businessId, $user)),
            'top_categories' => json_encode($this->topCategories($args, $businessId, $user)),
            'revenue_by_location' => json_encode($this->revenueByLocation($args, $businessId, $user)),
            'purchase_aggregate' => json_encode($this->purchaseAggregate($args, $businessId, $user)),
            'expense_aggregate' => json_encode($this->expenseAggregate($args, $businessId, $user)),
            'top_customers' => json_encode($this->topCustomers($args, $businessId, $user)),
            'top_suppliers' => json_encode($this->topSuppliers($args, $businessId, $user)),
            'stock_by_variation' => json_encode($this->stockByVariation($args, $businessId, $user)),
            'stock_expiry_near' => json_encode($this->stockExpiryNear($args, $businessId, $user)),
            'sell_return_aggregate' => json_encode($this->sellReturnAggregate($args, $businessId, $user)),
            'purchase_return_aggregate' => json_encode($this->purchaseReturnAggregate($args, $businessId, $user)),
            'opening_stock_aggregate' => json_encode($this->openingStockAggregate($args, $businessId, $user)),
            'sale_payment_mix' => json_encode($this->salePaymentMix($args, $businessId, $user)),
            'stock_adjustment_aggregate' => json_encode($this->stockAdjustmentAggregate($args, $businessId, $user)),
            'stock_transfer_summary' => json_encode($this->stockTransferSummary($args, $businessId, $user)),
            'sales_order_pipeline' => json_encode($this->salesOrderPipeline($args, $businessId, $user)),
            'purchase_order_pipeline' => json_encode($this->purchaseOrderPipeline($args, $businessId, $user)),
            'payroll_aggregate' => json_encode($this->payrollAggregate($args, $businessId, $user)),
            'purchase_sell_totals' => json_encode($this->purchaseSellTotals($args, $businessId, $user)),
            'profit_loss_snapshot' => json_encode($this->profitLossSnapshot($args, $businessId, $user)),
            'tax_report_snapshot' => json_encode($this->taxReportSnapshot($args, $businessId, $user)),
            'stock_report_rows' => json_encode($this->stockReportRows($args, $businessId, $user)),
            'stock_value_snapshot' => json_encode($this->stockValueSnapshot($args, $businessId, $user)),
            'trending_products_report' => json_encode($this->trendingProductsUnits($args, $businessId, $user)),
            'contact_search' => json_encode($this->contactSearch($args, $businessId, $user)),
            'contact_outstanding' => json_encode($this->contactOutstanding($args, $businessId, $user)),
            'receivables_ageing' => json_encode($this->receivablesAgeing($args, $businessId, $user)),
            'payables_ageing' => json_encode($this->payablesAgeing($args, $businessId, $user)),
            'product_search' => json_encode($this->productSearch($args, $businessId, $user)),
            'transaction_detail' => json_encode($this->transactionDetail($args, $businessId, $user)),
            'slow_moving_stock' => json_encode($this->slowMovingStock($args, $businessId, $user)),
            'product_margin_snapshot' => json_encode($this->productMarginSnapshot($args, $businessId, $user)),
            'lot_sell_trace' => json_encode($this->lotSellTrace($args, $businessId, $user)),
            'reorder_cover_hint' => json_encode($this->reorderCoverHint($args, $businessId, $user)),
            'sales_by_weekday' => json_encode($this->salesByWeekday($args, $businessId, $user)),
            'sales_by_hour_weekday' => json_encode($this->salesByHourWeekday($args, $businessId, $user)),
            'basket_metrics' => json_encode($this->basketMetrics($args, $businessId, $user)),
            'sales_by_cashier' => json_encode($this->salesByCashier($args, $businessId, $user)),
            'discount_summary' => json_encode($this->discountSummary($args, $businessId, $user)),
            'accounting_trial_balance_summary' => json_encode($this->accountingTrialBalanceSummary($args, $businessId, $user)),
            'accounting_ar_ageing_summary' => json_encode($this->accountingArAgeingSummary($args, $businessId, $user)),
            'accounting_ap_ageing_summary' => json_encode($this->accountingApAgeingSummary($args, $businessId, $user)),
            'accounting_balance_sheet_headlines' => json_encode($this->accountingBalanceSheetHeadlines($args, $businessId, $user)),
            'accounting_cash_flow_headlines' => json_encode($this->accountingCashFlowHeadlines($args, $businessId, $user)),
            default => json_encode(['ok' => false, 'error' => 'unknown_tool', 'name' => $name]),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function salesAggregate(array $args, int $businessId, User $user): array
    {
        $granularity = (string) ($args['granularity'] ?? 'total');
        if (! in_array($granularity, ['total', 'month', 'year'], true)) {
            return ['ok' => false, 'error' => 'invalid_granularity'];
        }

        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        $precision = $range['precision'];
        $symbol = $range['symbol'];
        $code = $range['code'];

        $base = $this->context->sellTransactionsQuery($businessId, $location_ids)
            ->whereBetween('transaction_date', [$start, $end]);

        if ($granularity === 'total') {
            $row = (clone $base)
                ->selectRaw('SUM(final_total) as revenue')
                ->selectRaw('COUNT(*) as invoices')
                ->first();

            $qty_selling = (float) $this->sellLinesInRangeQuery($businessId, $location_ids, $start, $end)
                ->sum(DB::raw($this->qtySellingUomSql()));

            return [
                'ok' => true,
                'granularity' => 'total',
                'currency_code' => $code,
                'currency_symbol' => $symbol,
                'rows' => [[
                    'revenue' => round((float) ($row->revenue ?? 0), $precision),
                    'invoices' => (int) ($row->invoices ?? 0),
                    'quantity_selling_uom' => $this->roundQuantity($qty_selling),
                ]],
            ];
        }

        if ($granularity === 'month') {
            $rows = (clone $base)
                ->selectRaw("DATE_FORMAT(transaction_date, '%Y-%m') as period")
                ->selectRaw('SUM(final_total) as revenue')
                ->selectRaw('COUNT(*) as invoices')
                ->groupBy(DB::raw("DATE_FORMAT(transaction_date, '%Y-%m')"))
                ->orderBy('period')
                ->limit(60)
                ->get();

            $qtyByPeriod = $this->sellLinesInRangeQuery($businessId, $location_ids, $start, $end)
                ->selectRaw("DATE_FORMAT(t.transaction_date, '%Y-%m') as period")
                ->selectRaw('SUM('.$this->qtySellingUomSql().') as quantity_selling_uom')
                ->groupBy(DB::raw("DATE_FORMAT(t.transaction_date, '%Y-%m')"))
                ->get()
                ->keyBy(fn ($row) => (string) $row->period);

            return [
                'ok' => true,
                'granularity' => 'month',
                'currency_code' => $code,
                'currency_symbol' => $symbol,
                'rows' => $rows->map(function ($r) use ($qtyByPeriod, $precision) {
                    $u = $qtyByPeriod->get((string) $r->period);

                    return [
                        'period' => (string) $r->period,
                        'revenue' => round((float) $r->revenue, $precision),
                        'invoices' => (int) $r->invoices,
                        'quantity_selling_uom' => $this->roundQuantity((float) ($u->quantity_selling_uom ?? 0)),
                    ];
                })->values()->all(),
            ];
        }

        $rows = (clone $base)
            ->selectRaw('YEAR(transaction_date) as period')
            ->selectRaw('SUM(final_total) as revenue')
            ->selectRaw('COUNT(*) as invoices')
            ->groupBy(DB::raw('YEAR(transaction_date)'))
            ->orderBy('period')
            ->limit(40)
            ->get();

        $qtyByPeriod = $this->sellLinesInRangeQuery($businessId, $location_ids, $start, $end)
            ->selectRaw('YEAR(t.transaction_date) as period')
            ->selectRaw('SUM('.$this->qtySellingUomSql().') as quantity_selling_uom')
            ->groupBy(DB::raw('YEAR(t.transaction_date)'))
            ->get()
            ->keyBy(fn ($row) => (string) $row->period);

        return [
            'ok' => true,
            'granularity' => 'year',
            'currency_code' => $code,
            'currency_symbol' => $symbol,
            'rows' => $rows->map(function ($r) use ($qtyByPeriod, $precision) {
                $u = $qtyByPeriod->get((string) $r->period);

                return [
                    'period' => (string) $r->period,
                    'revenue' => round((float) $r->revenue, $precision),
                    'invoices' => (int) $r->invoices,
                    'quantity_selling_uom' => $this->roundQuantity((float) ($u->quantity_selling_uom ?? 0)),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function topProducts(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        $precision = $range['precision'];
        $symbol = $range['symbol'];

        $limit = isset($args['limit']) ? (int) $args['limit'] : 10;
        $limit = max(1, min(25, $limit));

        $sort_by = (string) ($args['sort_by'] ?? 'revenue');
        if (! in_array($sort_by, ['revenue', 'quantity'], true)) {
            $sort_by = 'revenue';
        }

        $qtySql = $this->qtySellingUomSql();

        $q = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->leftJoin('units as sell_unit', 'sell_unit.id', '=', 'tsl.sub_unit_id')
            ->leftJoin('units as base_u', 'base_u.id', '=', 'p.unit_id')
            ->whereNull('tsl.parent_sell_line_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($sub) => $sub->whereIn('t.location_id', $location_ids))
            ->groupBy('p.id', 'p.name');

        if ($sort_by === 'quantity') {
            $q->orderByRaw('SUM('.$qtySql.') DESC');
        } else {
            $q->orderByRaw('SUM(tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)) DESC');
        }

        $rows = $q->limit($limit)
            ->selectRaw('p.name as product_name')
            ->selectRaw('SUM(tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)) as revenue')
            ->selectRaw('SUM('.$qtySql.') as quantity_selling_uom')
            ->selectRaw('GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(sell_unit.short_name), ""), NULLIF(TRIM(base_u.short_name), "")) ORDER BY COALESCE(sell_unit.short_name, base_u.short_name) SEPARATOR " | ") as selling_unit_labels')
            ->get();

        return [
            'ok' => true,
            'sort_by' => $sort_by,
            'currency_symbol' => $symbol,
            'rows' => $rows->map(fn ($r) => [
                'product_name' => (string) $r->product_name,
                'quantity_selling_uom' => $this->roundQuantity((float) $r->quantity_selling_uom),
                'selling_unit_labels' => (string) ($r->selling_unit_labels ?? ''),
                'revenue' => round((float) $r->revenue, $precision),
            ])->values()->all(),
        ];
    }

    /**
     * Time-bucketed sell-line aggregates for a single product (final sells, main lines only).
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function productSalesTrend(array $args, int $businessId, User $user): array
    {
        $granularity = strtolower((string) ($args['granularity'] ?? 'month'));
        if (! in_array($granularity, ['day', 'week', 'month'], true)) {
            return ['ok' => false, 'error' => 'invalid_granularity'];
        }

        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        if (isset($args['location_id']) && is_numeric($args['location_id'])) {
            $lid = (int) $args['location_id'];
            $permitted = $user->permitted_locations();
            if ($permitted === 'all' || (is_array($permitted) && in_array($lid, $permitted, true))) {
                $location_ids = [$lid];
            }
        }

        $precision = $range['precision'];
        $symbol = $range['symbol'];

        $product_id_arg = isset($args['product_id']) ? (int) $args['product_id'] : 0;
        $name_query = isset($args['name_query']) ? trim((string) $args['name_query']) : '';

        if ($product_id_arg <= 0 && $name_query === '') {
            return ['ok' => false, 'error' => 'missing_product_id_or_name_query'];
        }

        if ($name_query !== '' && mb_strlen($name_query) < 2) {
            return ['ok' => false, 'error' => 'name_query_too_short'];
        }

        $days = $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
        if ($granularity === 'day' && $days > 200) {
            return [
                'ok' => false,
                'error' => 'range_too_long_for_day_granularity',
                'max_days' => 200,
                'hint' => 'Use granularity week or month, or shorten the date range.',
            ];
        }

        $resolved_product_id = $product_id_arg;
        $matched_product_name = null;

        if ($resolved_product_id <= 0) {
            $like = '%'.addcslashes($name_query, '%_\\').'%';
            $matches = DB::table('products as p')
                ->leftJoin('variations as v', function ($join) {
                    $join->on('v.product_id', '=', 'p.id')->whereNull('v.deleted_at');
                })
                ->where('p.business_id', $businessId)
                ->where(function ($w) use ($like) {
                    $w->where('p.name', 'like', $like)
                        ->orWhere('v.name', 'like', $like)
                        ->orWhere('v.sub_sku', 'like', $like);
                })
                ->groupBy('p.id', 'p.name')
                ->orderBy('p.name')
                ->limit(15)
                ->selectRaw('p.id as product_id')
                ->selectRaw('p.name as product_name')
                ->get();

            if ($matches->isEmpty()) {
                return [
                    'ok' => true,
                    'ambiguous' => false,
                    'product_id' => null,
                    'product_name' => null,
                    'granularity' => $granularity,
                    'currency_symbol' => $symbol,
                    'rows' => [],
                    'note' => 'No product or variation matched that text. Try a shorter or alternate substring (e.g. brand name), confirm the product exists and has finalized sell lines in the range, or locate products.id from TeamPOS and pass product_id.',
                ];
            }

            if ($matches->count() > 1) {
                return [
                    'ok' => true,
                    'ambiguous' => true,
                    'matches' => $matches->map(fn ($m) => [
                        'product_id' => (int) $m->product_id,
                        'product_name' => (string) $m->product_name,
                    ])->values()->all(),
                    'granularity' => $granularity,
                    'currency_symbol' => $symbol,
                    'rows' => [],
                    'note' => 'Several products matched name_query. Call product_sales_trend again with the correct product_id.',
                ];
            }

            $resolved_product_id = (int) $matches->first()->product_id;
            $matched_product_name = (string) $matches->first()->product_name;
        } else {
            $row = DB::table('products')
                ->where('business_id', $businessId)
                ->where('id', $resolved_product_id)
                ->select('id', 'name')
                ->first();
            if (! $row) {
                return ['ok' => false, 'error' => 'product_not_found_for_business'];
            }
            $matched_product_name = (string) $row->name;
        }

        $period_sql = match ($granularity) {
            'day' => 'DATE(t.transaction_date)',
            'week' => "DATE_FORMAT(t.transaction_date, '%x-W%v')",
            default => "DATE_FORMAT(t.transaction_date, '%Y-%m')",
        };

        $qty_sql = $this->qtySellingUomSql();

        $rows = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->leftJoin('units as sell_unit', 'sell_unit.id', '=', 'tsl.sub_unit_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->whereNull('tsl.parent_sell_line_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->where('tsl.product_id', $resolved_product_id)
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->groupBy(DB::raw($period_sql))
            ->orderBy(DB::raw($period_sql))
            ->limit(400)
            ->selectRaw($period_sql.' as period')
            ->selectRaw('SUM(tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)) as revenue')
            ->selectRaw('SUM('.$qty_sql.') as quantity_selling_uom')
            ->selectRaw('COUNT(DISTINCT t.id) as invoices')
            ->get();

        return [
            'ok' => true,
            'ambiguous' => false,
            'product_id' => $resolved_product_id,
            'product_name' => $matched_product_name,
            'granularity' => $granularity,
            'currency_symbol' => $symbol,
            'rows' => $rows->map(fn ($r) => [
                'period' => (string) $r->period,
                'revenue' => round((float) $r->revenue, $precision),
                'quantity_selling_uom' => $this->roundQuantity((float) $r->quantity_selling_uom),
                'invoices' => (int) $r->invoices,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function topCategories(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        $precision = $range['precision'];
        $symbol = $range['symbol'];

        $limit = isset($args['limit']) ? (int) $args['limit'] : 10;
        $limit = max(1, min(20, $limit));

        $sort_by = (string) ($args['sort_by'] ?? 'revenue');
        if (! in_array($sort_by, ['revenue', 'quantity'], true)) {
            $sort_by = 'revenue';
        }

        $qtySql = $this->qtySellingUomSql();

        $q = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('units as sell_unit', 'sell_unit.id', '=', 'tsl.sub_unit_id')
            ->whereNull('tsl.parent_sell_line_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($sub) => $sub->whereIn('t.location_id', $location_ids))
            ->groupBy(DB::raw('COALESCE(NULLIF(c.name, ""), "Uncategorized")'));

        if ($sort_by === 'quantity') {
            $q->orderByRaw('SUM('.$qtySql.') DESC');
        } else {
            $q->orderByRaw('SUM(tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)) DESC');
        }

        $rows = $q->limit($limit)
            ->selectRaw('COALESCE(NULLIF(c.name, ""), "Uncategorized") as category_name')
            ->selectRaw('SUM(tsl.quantity * COALESCE(tsl.unit_price_inc_tax, tsl.unit_price, 0)) as revenue')
            ->selectRaw('SUM('.$qtySql.') as quantity_selling_uom')
            ->get();

        return [
            'ok' => true,
            'sort_by' => $sort_by,
            'currency_symbol' => $symbol,
            'rows' => $rows->map(fn ($r) => [
                'category_name' => (string) $r->category_name,
                'quantity_selling_uom' => $this->roundQuantity((float) $r->quantity_selling_uom),
                'revenue' => round((float) $r->revenue, $precision),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function revenueByLocation(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        $precision = $range['precision'];
        $symbol = $range['symbol'];

        $rows = DB::table('transactions as t')
            ->join('business_locations as bl', 'bl.id', '=', 't.location_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->groupBy('bl.id', 'bl.name')
            ->orderByDesc(DB::raw('SUM(t.final_total)'))
            ->limit(25)
            ->selectRaw('bl.id as location_id')
            ->selectRaw('bl.name as location_name')
            ->selectRaw('SUM(t.final_total) as revenue')
            ->selectRaw('COUNT(*) as invoices')
            ->get();

        $qtyByLocation = $this->sellLinesInRangeQuery($businessId, $location_ids, $start, $end)
            ->selectRaw('t.location_id')
            ->selectRaw('SUM('.$this->qtySellingUomSql().') as quantity_selling_uom')
            ->groupBy('t.location_id')
            ->pluck('quantity_selling_uom', 'location_id');

        return [
            'ok' => true,
            'currency_symbol' => $symbol,
            'rows' => $rows->map(function ($r) use ($qtyByLocation, $precision) {
                $lid = (int) $r->location_id;
                $qty = (float) ($qtyByLocation->get($lid) ?? $qtyByLocation->get((string) $lid) ?? 0);

                return [
                    'location_name' => (string) $r->location_name,
                    'revenue' => round((float) $r->revenue, $precision),
                    'invoices' => (int) $r->invoices,
                    'quantity_selling_uom' => $this->roundQuantity($qty),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Completed purchases (goods received), scoped like sells.
     *
     * @param  ?array<int>  $location_ids
     * @return \Illuminate\Database\Query\Builder
     */
    protected function purchaseTransactionsQuery(int $businessId, ?array $location_ids, Carbon $start, Carbon $end)
    {
        return DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'purchase')
            ->where('status', 'received')
            ->whereBetween('transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($q) => $q->whereIn('location_id', $location_ids));
    }

    /**
     * Expenses and refunds (net).
     *
     * @param  ?array<int>  $location_ids
     * @return \Illuminate\Database\Query\Builder
     */
    protected function expenseTransactionsQuery(int $businessId, ?array $location_ids, Carbon $start, Carbon $end)
    {
        return DB::table('transactions')
            ->where('business_id', $businessId)
            ->whereIn('type', ['expense', 'expense_refund'])
            ->where('status', 'final')
            ->whereBetween('transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($q) => $q->whereIn('location_id', $location_ids));
    }

    protected function expenseNetSql(): string
    {
        return '(CASE WHEN type = \'expense\' THEN final_total WHEN type = \'expense_refund\' THEN -1 * final_total ELSE 0 END)';
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function purchaseAggregate(array $args, int $businessId, User $user): array
    {
        $granularity = (string) ($args['granularity'] ?? 'total');
        if (! in_array($granularity, ['total', 'month', 'year'], true)) {
            return ['ok' => false, 'error' => 'invalid_granularity'];
        }

        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        $precision = $range['precision'];
        $symbol = $range['symbol'];
        $code = $range['code'];

        $base = $this->purchaseTransactionsQuery($businessId, $location_ids, $start, $end);

        if ($granularity === 'total') {
            $row = (clone $base)
                ->selectRaw('SUM(final_total) as spend')
                ->selectRaw('COUNT(*) as bills')
                ->first();

            return [
                'ok' => true,
                'granularity' => 'total',
                'basis' => 'purchase_received',
                'currency_code' => $code,
                'currency_symbol' => $symbol,
                'rows' => [[
                    'spend' => round((float) ($row->spend ?? 0), $precision),
                    'bills' => (int) ($row->bills ?? 0),
                ]],
            ];
        }

        if ($granularity === 'month') {
            $rows = (clone $base)
                ->selectRaw("DATE_FORMAT(transaction_date, '%Y-%m') as period")
                ->selectRaw('SUM(final_total) as spend')
                ->selectRaw('COUNT(*) as bills')
                ->groupBy(DB::raw("DATE_FORMAT(transaction_date, '%Y-%m')"))
                ->orderBy('period')
                ->limit(60)
                ->get();

            return [
                'ok' => true,
                'granularity' => 'month',
                'basis' => 'purchase_received',
                'currency_code' => $code,
                'currency_symbol' => $symbol,
                'rows' => $rows->map(fn ($r) => [
                    'period' => (string) $r->period,
                    'spend' => round((float) $r->spend, $precision),
                    'bills' => (int) $r->bills,
                ])->values()->all(),
            ];
        }

        $rows = (clone $base)
            ->selectRaw('YEAR(transaction_date) as period')
            ->selectRaw('SUM(final_total) as spend')
            ->selectRaw('COUNT(*) as bills')
            ->groupBy(DB::raw('YEAR(transaction_date)'))
            ->orderBy('period')
            ->limit(40)
            ->get();

        return [
            'ok' => true,
            'granularity' => 'year',
            'basis' => 'purchase_received',
            'currency_code' => $code,
            'currency_symbol' => $symbol,
            'rows' => $rows->map(fn ($r) => [
                'period' => (string) $r->period,
                'spend' => round((float) $r->spend, $precision),
                'bills' => (int) $r->bills,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function expenseAggregate(array $args, int $businessId, User $user): array
    {
        $granularity = (string) ($args['granularity'] ?? 'total');
        if (! in_array($granularity, ['total', 'month', 'year'], true)) {
            return ['ok' => false, 'error' => 'invalid_granularity'];
        }

        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        $precision = $range['precision'];
        $symbol = $range['symbol'];
        $code = $range['code'];

        $net = $this->expenseNetSql();
        $base = $this->expenseTransactionsQuery($businessId, $location_ids, $start, $end);

        if ($granularity === 'total') {
            $row = (clone $base)
                ->selectRaw("SUM({$net}) as net_expense")
                ->selectRaw('SUM(CASE WHEN type = \'expense\' THEN 1 ELSE 0 END) as expense_rows')
                ->selectRaw('SUM(CASE WHEN type = \'expense_refund\' THEN 1 ELSE 0 END) as refund_rows')
                ->first();

            return [
                'ok' => true,
                'granularity' => 'total',
                'basis' => 'expense_minus_refund',
                'currency_code' => $code,
                'currency_symbol' => $symbol,
                'rows' => [[
                    'net_expense' => round((float) ($row->net_expense ?? 0), $precision),
                    'expense_transactions' => (int) ($row->expense_rows ?? 0),
                    'refund_transactions' => (int) ($row->refund_rows ?? 0),
                ]],
            ];
        }

        if ($granularity === 'month') {
            $rows = (clone $base)
                ->selectRaw("DATE_FORMAT(transaction_date, '%Y-%m') as period")
                ->selectRaw("SUM({$net}) as net_expense")
                ->selectRaw('SUM(CASE WHEN type = \'expense\' THEN 1 ELSE 0 END) as expense_transactions')
                ->groupBy(DB::raw("DATE_FORMAT(transaction_date, '%Y-%m')"))
                ->orderBy('period')
                ->limit(60)
                ->get();

            return [
                'ok' => true,
                'granularity' => 'month',
                'basis' => 'expense_minus_refund',
                'currency_code' => $code,
                'currency_symbol' => $symbol,
                'rows' => $rows->map(fn ($r) => [
                    'period' => (string) $r->period,
                    'net_expense' => round((float) $r->net_expense, $precision),
                    'expense_transactions' => (int) $r->expense_transactions,
                ])->values()->all(),
            ];
        }

        $rows = (clone $base)
            ->selectRaw('YEAR(transaction_date) as period')
            ->selectRaw("SUM({$net}) as net_expense")
            ->selectRaw('SUM(CASE WHEN type = \'expense\' THEN 1 ELSE 0 END) as expense_transactions')
            ->groupBy(DB::raw('YEAR(transaction_date)'))
            ->orderBy('period')
            ->limit(40)
            ->get();

        return [
            'ok' => true,
            'granularity' => 'year',
            'basis' => 'expense_minus_refund',
            'currency_code' => $code,
            'currency_symbol' => $symbol,
            'rows' => $rows->map(fn ($r) => [
                'period' => (string) $r->period,
                'net_expense' => round((float) $r->net_expense, $precision),
                'expense_transactions' => (int) $r->expense_transactions,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function topCustomers(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        $precision = $range['precision'];
        $symbol = $range['symbol'];

        $limit = isset($args['limit']) ? (int) $args['limit'] : 10;
        $limit = max(1, min(25, $limit));

        $rows = DB::table('transactions as t')
            ->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->whereNull('c.deleted_at')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereIn('c.type', ['customer', 'both'])
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->groupBy('c.id', 'c.name', 'c.supplier_business_name')
            ->orderByDesc(DB::raw('SUM(t.final_total)'))
            ->limit($limit)
            ->selectRaw('c.id as contact_id')
            ->selectRaw('COALESCE(NULLIF(TRIM(c.name), ""), NULLIF(TRIM(c.supplier_business_name), ""), CONCAT("Customer #", c.id)) as customer_name')
            ->selectRaw('SUM(t.final_total) as revenue')
            ->selectRaw('COUNT(*) as invoices')
            ->get();

        return [
            'ok' => true,
            'currency_symbol' => $symbol,
            'rows' => $rows->map(fn ($r) => [
                'customer_name' => (string) $r->customer_name,
                'revenue' => round((float) $r->revenue, $precision),
                'invoices' => (int) $r->invoices,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function topSuppliers(array $args, int $businessId, User $user): array
    {
        $range = $this->parseDateRange($args, $businessId, $user);
        if (isset($range['ok']) && $range['ok'] === false) {
            return $range;
        }
        $start = $range['start'];
        $end = $range['end'];
        $location_ids = $range['location_ids'];
        $precision = $range['precision'];
        $symbol = $range['symbol'];

        $limit = isset($args['limit']) ? (int) $args['limit'] : 10;
        $limit = max(1, min(25, $limit));

        $rows = DB::table('transactions as t')
            ->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->whereNull('c.deleted_at')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'purchase')
            ->where('t.status', 'received')
            ->whereIn('c.type', ['supplier', 'both'])
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids))
            ->groupBy('c.id', 'c.name', 'c.supplier_business_name')
            ->orderByDesc(DB::raw('SUM(t.final_total)'))
            ->limit($limit)
            ->selectRaw('c.id as contact_id')
            ->selectRaw('COALESCE(NULLIF(TRIM(c.name), ""), NULLIF(TRIM(c.supplier_business_name), ""), CONCAT("Supplier #", c.id)) as supplier_name')
            ->selectRaw('SUM(t.final_total) as spend')
            ->selectRaw('COUNT(*) as bills')
            ->get();

        return [
            'ok' => true,
            'currency_symbol' => $symbol,
            'rows' => $rows->map(fn ($r) => [
                'supplier_name' => (string) $r->supplier_name,
                'spend' => round((float) $r->spend, $precision),
                'bills' => (int) $r->bills,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function stockByVariation(array $args, int $businessId, User $user): array
    {
        $business = Business::with('currency')->find($businessId);
        if (! $business) {
            return ['ok' => false, 'error' => 'business_not_found'];
        }

        $currency = $business->currency;
        $symbol = $currency ? $currency->symbol : '';

        $permitted = $user->permitted_locations();
        $location_ids = null;
        if ($permitted !== 'all') {
            $location_ids = is_array($permitted) ? $permitted : [];
        }

        $sort_by = (string) ($args['sort_by'] ?? 'low_qty');
        if (! in_array($sort_by, ['low_qty', 'high_qty'], true)) {
            $sort_by = 'low_qty';
        }

        $limit = isset($args['limit']) ? (int) $args['limit'] : 25;
        $limit = max(1, min(50, $limit));

        $q = DB::table('variation_location_details as vld')
            ->join('products as p', 'p.id', '=', 'vld.product_id')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->join('product_variations as pv', 'pv.id', '=', 'v.product_variation_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('p.business_id', $businessId)
            ->where('p.enable_stock', 1)
            ->where('p.is_inactive', 0)
            ->whereNull('v.deleted_at')
            ->when($location_ids !== null, fn ($sub) => $sub->whereIn('vld.location_id', $location_ids))
            ->groupBy('v.id');

        if ($sort_by === 'high_qty') {
            $q->orderByDesc(DB::raw('SUM(vld.qty_available)'));
        } else {
            $q->orderBy(DB::raw('SUM(vld.qty_available)'));
        }

        $rows = $q->limit($limit)
            ->selectRaw('MAX(p.name) as product_name')
            ->selectRaw('MAX(pv.name) as variation_name')
            ->selectRaw('MAX(COALESCE(NULLIF(TRIM(v.sub_sku), ""), NULLIF(TRIM(p.sku), ""), "")) as sku')
            ->selectRaw('MAX(u.short_name) as unit_short_name')
            ->selectRaw('SUM(vld.qty_available) as qty_available')
            ->selectRaw('MAX(p.alert_quantity) as alert_quantity')
            ->get();

        return [
            'ok' => true,
            'basis' => 'current_qty_available_summed_per_variation',
            'currency_symbol' => $symbol,
            'sort_by' => $sort_by,
            'rows' => $rows->map(fn ($r) => [
                'product_name' => (string) $r->product_name,
                'variation_name' => (string) ($r->variation_name ?? ''),
                'sku' => (string) ($r->sku ?? ''),
                'unit_short_name' => (string) ($r->unit_short_name ?? ''),
                'qty_available' => $this->roundQuantity((float) $r->qty_available),
                'alert_quantity' => $r->alert_quantity !== null ? $this->roundQuantity((float) $r->alert_quantity) : null,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function stockExpiryNear(array $args, int $businessId, User $user): array
    {
        $business = Business::find($businessId);
        if (! $business) {
            return ['ok' => false, 'error' => 'business_not_found'];
        }

        if (! (bool) $business->enable_product_expiry) {
            return [
                'ok' => false,
                'error' => 'product_expiry_disabled',
                'hint' => 'Enable product expiry in Business Settings (same prerequisite as the Stock Expiry Report).',
            ];
        }

        $within_days = isset($args['within_days']) ? (int) $args['within_days'] : 90;
        $within_days = max(1, min(730, $within_days));

        $include_expired = ! empty($args['include_expired']);

        $limit = isset($args['limit']) ? (int) $args['limit'] : 25;
        $limit = max(1, min(50, $limit));

        $permitted = $user->permitted_locations();
        $location_ids = null;
        if ($permitted !== 'all') {
            $location_ids = is_array($permitted) ? $permitted : [];
        }

        $requested_location_id = isset($args['location_id']) ? (int) $args['location_id'] : 0;
        if ($requested_location_id > 0) {
            if ($location_ids !== null && ! in_array($requested_location_id, $location_ids, true)) {
                return ['ok' => false, 'error' => 'location_not_permitted'];
            }
        }

        $tz = is_string($business->time_zone) && trim($business->time_zone) !== ''
            ? trim($business->time_zone)
            : (string) config('app.timezone');

        $today = Carbon::now($tz)->startOfDay();
        $until = $today->copy()->addDays($within_days)->startOfDay();

        $todayStr = $today->toDateString();
        $untilStr = $until->toDateString();

        $stockSql = 'SUM(COALESCE(pl.quantity, 0) - COALESCE(pl.quantity_sold, 0) - COALESCE(pl.quantity_adjusted, 0) - COALESCE(pl.quantity_returned, 0))';

        $query = DB::table('purchase_lines as pl')
            ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
            ->join('products as p', 'pl.product_id', '=', 'p.id')
            ->join('variations as v', 'pl.variation_id', '=', 'v.id')
            ->join('product_variations as pv', 'v.product_variation_id', '=', 'pv.id')
            ->join('business_locations as l', 't.location_id', '=', 'l.id')
            ->leftJoin('units as u', 'p.unit_id', '=', 'u.id')
            ->where('t.business_id', $businessId)
            ->where('p.enable_stock', 1)
            ->whereNotNull('pl.exp_date')
            ->whereNull('v.deleted_at')
            ->where(function ($q) use ($todayStr, $untilStr, $include_expired) {
                $q->whereBetween(DB::raw('DATE(pl.exp_date)'), [$todayStr, $untilStr]);
                if ($include_expired) {
                    $q->orWhereRaw('DATE(pl.exp_date) < ?', [$todayStr]);
                }
            });

        if ($requested_location_id > 0) {
            $query->where('t.location_id', $requested_location_id)
                ->join('product_locations as ploc', function ($join) use ($requested_location_id) {
                    $join->on('ploc.product_id', '=', 'p.id')
                        ->where('ploc.location_id', '=', $requested_location_id);
                });
        } elseif ($location_ids !== null) {
            $query->whereIn('t.location_id', $location_ids);
        }

        $rows = $query
            ->groupBy('pl.variation_id', 'pl.exp_date', 'pl.lot_number')
            ->havingRaw($stockSql.' > 0')
            ->orderByRaw('DATE(pl.exp_date) ASC')
            ->limit($limit)
            ->selectRaw('MIN(pl.id) as purchase_line_id')
            ->selectRaw('MAX(p.name) as product')
            ->selectRaw('MAX(p.sku) as sku')
            ->selectRaw('MAX(p.type) as product_type')
            ->selectRaw('MAX(v.name) as variation')
            ->selectRaw('MAX(v.sub_sku) as sub_sku')
            ->selectRaw('MAX(pv.name) as product_variation')
            ->selectRaw('MAX(l.name) as location')
            ->selectRaw('MAX(pl.mfg_date) as mfg_date')
            ->selectRaw('MAX(pl.exp_date) as exp_date')
            ->selectRaw('MAX(u.short_name) as unit')
            ->selectRaw($stockSql.' as stock_left')
            ->selectRaw('MAX(t.ref_no) as ref_no')
            ->selectRaw('MAX(pl.lot_number) as lot_number')
            ->get();

        return [
            'ok' => true,
            'basis' => 'purchase_lines_expiry_like_stock_expiry_report',
            'timezone' => $tz,
            'reference_date' => $todayStr,
            'within_days' => $within_days,
            'window_end' => $untilStr,
            'include_expired' => $include_expired,
            'rows' => $rows->map(function ($r) use ($today, $tz) {
                $product_name = (string) $r->product;
                if (($r->product_type ?? '') === 'variable') {
                    $product_name = $product_name.' - '.($r->product_variation ?? '').' - '.($r->variation ?? '').' ('.($r->sub_sku ?? '').')';
                }

                $exp_day = Carbon::parse((string) $r->exp_date)->timezone($tz)->startOfDay();

                return [
                    'product_display' => $product_name,
                    'parent_sku' => (string) ($r->sku ?? ''),
                    'variation_sub_sku' => (string) ($r->sub_sku ?? ''),
                    'location' => (string) ($r->location ?? ''),
                    'exp_date' => $exp_day->toDateString(),
                    'mfg_date' => $r->mfg_date ? Carbon::parse((string) $r->mfg_date)->timezone($tz)->toDateString() : null,
                    'stock_left' => $this->roundQuantity((float) $r->stock_left),
                    'unit_short_name' => (string) ($r->unit ?? ''),
                    'lot_number' => $r->lot_number !== null && $r->lot_number !== '' ? (string) $r->lot_number : null,
                    'purchase_ref_no' => (string) ($r->ref_no ?? ''),
                    'purchase_line_id' => (int) $r->purchase_line_id,
                    'days_until_expiry' => (int) $today->diffInDays($exp_day, false),
                ];
            })->values()->all(),
        ];
    }

    /**
     * SQL fragment: net quantity in the invoice line unit (matches POS display logic).
     * TeamPOS stores tsl.quantity in smallest-unit form; divide by sub_unit.base_unit_multiplier.
     */
    protected function qtySellingUomSql(): string
    {
        return '(tsl.quantity - COALESCE(tsl.quantity_returned, 0)) / NULLIF(GREATEST(COALESCE(sell_unit.base_unit_multiplier, 1), 0.00000001), 0)';
    }

    /**
     * Sell lines on final sell transactions in range (for quantity aggregates).
     * Excludes modifier/combo child rows; joins sell_unit for UoM conversion.
     *
     * @param  ?array<int>  $location_ids
     * @return \Illuminate\Database\Query\Builder
     */
    protected function sellLinesInRangeQuery(int $businessId, ?array $location_ids, Carbon $start, Carbon $end)
    {
        return DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->leftJoin('units as sell_unit', 'sell_unit.id', '=', 'tsl.sub_unit_id')
            ->whereNull('tsl.parent_sell_line_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->when($location_ids !== null, fn ($q) => $q->whereIn('t.location_id', $location_ids));
    }

    protected function roundQuantity(float $qty): float
    {
        return round($qty, 4);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function parseDateRange(array $args, int $businessId, User $user): array
    {
        $startRaw = $args['start_date'] ?? null;
        $endRaw = $args['end_date'] ?? null;
        if (! is_string($startRaw) || ! is_string($endRaw)) {
            return ['ok' => false, 'error' => 'missing_dates'];
        }

        try {
            $start = Carbon::parse($startRaw)->startOfDay();
            $end = Carbon::parse($endRaw)->endOfDay();
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'invalid_date_format'];
        }

        if ($end->lt($start)) {
            return ['ok' => false, 'error' => 'end_before_start'];
        }

        $maxDays = (int) config('aibusinessmanager.max_tool_date_span_days', 800);
        if ($start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) > $maxDays) {
            return ['ok' => false, 'error' => 'date_span_exceeds_limit', 'max_days' => $maxDays];
        }

        $business = Business::with('currency')->find($businessId);
        if (! $business) {
            return ['ok' => false, 'error' => 'business_not_found'];
        }

        $currency = $business->currency;
        $symbol = $currency ? $currency->symbol : '';
        $code = $currency ? $currency->code : '';
        $precision = (int) ($business->currency_precision ?? 2);

        $permitted = $user->permitted_locations();
        $location_ids = null;
        if ($permitted !== 'all') {
            $location_ids = is_array($permitted) ? $permitted : [];
        }

        return [
            'start' => $start,
            'end' => $end,
            'location_ids' => $location_ids,
            'precision' => $precision,
            'symbol' => $symbol,
            'code' => $code,
        ];
    }
}
