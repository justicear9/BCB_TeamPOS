<?php

namespace Modules\Cashier\Services;

use App\Business;
use App\BusinessLocation;
use App\Contact;
use App\InvoiceLayout;
use App\Product;
use App\SellingPriceGroup;
use App\Transaction;
use App\TransactionPayment;
use App\TransactionSellLine;
use App\User;
use App\Utils\ProductUtil;
use App\Utils\Util;
use App\VariationGroupPrice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CatalogPull
{
    /** Pulls overlap by this much so a write that lands during a pull is not skipped. */
    private const CURSOR_OVERLAP_SECONDS = 120;

    public function __construct(
        private Util $util,
        private ProductUtil $productUtil,
        private CashierRegister $register
    ) {
    }

    public function locations(User $user): array
    {
        auth()->setUser($user);
        $query = BusinessLocation::where('business_id', $user->business_id);
        $permitted = $user->permitted_locations($user->business_id);
        if ($permitted !== 'all') {
            $query->whereIn('id', $permitted);
        }

        $businessName = (string) Business::where('id', $user->business_id)->value('name');

        return $query->orderBy('name')->get(['id', 'name'])
            ->map(fn ($location) => [
                'id' => $location->id,
                'name' => $location->name,
                'business_name' => $businessName,
            ])
            ->all();
    }

    public function changes(User $user, int $locationId, ?string $since): array
    {
        auth()->setUser($user);
        if (! User::can_access_this_location($locationId, $user->business_id)) {
            abort(403, 'Location is not allowed');
        }

        $location = BusinessLocation::where('business_id', $user->business_id)->find($locationId);
        if (! $location) {
            abort(403, 'Location is not allowed');
        }
        $sinceAt = $this->cursor($since);
        $business = Business::findOrFail($user->business_id);
        $businessName = (string) $business->name;
        $priceGroups = $this->priceGroups($user, $location);

        return [
            'server_time' => $this->databaseTime(),
            'location' => [
                'id' => $location->id,
                'name' => $location->name,
                'business_name' => $businessName,
            ],
            'price_groups' => $priceGroups,
            'products' => $this->products($location, array_column($priceGroups['options'], 'id')),
            'customers' => $this->customers($user->business_id, $location->id),
            'payment_methods' => $this->paymentMethods($location, $user->business_id),
            'receipt' => $this->receiptLayout($location, $businessName),
            'sales' => $this->sales($user->business_id, $location->id, $sinceAt),
            'removed_product_variation_ids' => [],
            'removed_customer_ids' => $this->removedCustomers($user->business_id, $sinceAt),
            'cashier' => $this->cashierSettings($user, $business),
            'register' => $this->register->summary($user),
        ];
    }

    private function databaseTime(): string
    {
        return (string) DB::selectOne('select now() as server_time')->server_time;
    }

    private function cursor(?string $since): ?Carbon
    {
        if (! $since) {
            return null;
        }

        $cursor = Carbon::parse($since);
        if ($cursor->greaterThan(Carbon::parse($this->databaseTime()))) {
            return null;
        }

        return $cursor->subSeconds(self::CURSOR_OVERLAP_SECONDS);
    }

    /**
     * Always the full list for the shop. Stock changes in TeamPOS do not touch
     * updated_at, so an incremental pull would miss them.
     */
    /**
     * The price groups this cashier may sell at, as the POS screen offers them.
     * Id 0 is the default selling price.
     */
    private function priceGroups(User $user, BusinessLocation $location): array
    {
        $options = [];
        if ($user->can('access_default_selling_price')) {
            $options[] = ['id' => 0, 'name' => 'Default price'];
        }
        $groups = SellingPriceGroup::where('business_id', $location->business_id)->active()->orderBy('name')->get(['id', 'name']);
        foreach ($groups as $group) {
            if ($user->can('selling_price_group.'.$group->id)) {
                $options[] = ['id' => $group->id, 'name' => $group->name];
            }
        }

        $ids = array_column($options, 'id');
        $locationGroup = (int) $location->selling_price_group_id;
        $default = in_array($locationGroup, $ids, true) ? $locationGroup : ($ids[0] ?? null);

        return ['options' => $options, 'default_id' => $default];
    }

    private function products(BusinessLocation $location, array $priceGroupIds): array
    {
        $locationId = $location->id;
        $products = Product::where('business_id', $location->business_id)
            ->where('is_inactive', 0)
            ->where('not_for_selling', 0)
            ->where('type', '!=', 'modifier')
            ->forLocation($locationId)
            ->with(['variations.variation_location_details' => function ($q) use ($locationId) {
                $q->where('location_id', $locationId);
            }])
            ->get();

        $groupRows = VariationGroupPrice::whereIn('variation_id', $products->pluck('variations')->flatten()->pluck('id'))
            ->whereIn('price_group_id', array_filter($priceGroupIds))
            ->get()
            ->groupBy('variation_id');

        $rows = [];
        foreach ($products as $product) {
            foreach ($product->variations as $variation) {
                $prices = [];
                foreach ($priceGroupIds as $groupId) {
                    $row = $groupId ? $groupRows->get($variation->id)?->firstWhere('price_group_id', $groupId) : null;
                    $price = (float) $variation->sell_price_inc_tax;
                    if ($row) {
                        $groupPrice = $row->price_type === 'percentage'
                            ? (float) $this->productUtil->calc_percentage($variation->sell_price_inc_tax, $row->price_inc_tax)
                            : (float) $row->price_inc_tax;
                        if ($groupPrice > 0) {
                            $price = $groupPrice;
                        }
                    }
                    $prices[(string) $groupId] = (string) round($price, 4);
                }
                $stock = $variation->variation_location_details->first();
                $qty = (float) ($stock->qty_available ?? 0);
                if ($product->type === 'combo') {
                    $qty = $this->productUtil->calculateComboQuantity($locationId, $variation->combo_variations ?? []);
                }
                $rows[] = [
                    'product_id' => $product->id,
                    'variation_id' => $variation->id,
                    'name' => $product->name,
                    'variation_name' => $variation->name,
                    'sku' => $variation->sub_sku ?: $product->sku,
                    'sell_price' => (string) SaleCreator::listPrice($this->productUtil, $variation, $location, $product->tax),
                    'prices' => (object) $prices,
                    'qty_available' => (string) $qty,
                    'enable_stock' => $product->type === 'combo' ? 1 : (int) $product->enable_stock,
                ];
            }
        }

        return $rows;
    }

    private function cashierSettings(User $user, Business $business): array
    {
        return [
            'can_override_price' => $user->can('edit_product_price_from_pos_screen'),
            'can_discount' => ! SaleCreator::restricted($user, 'disable_discount'),
            'can_sell_on_credit' => ! SaleCreator::restricted($user, 'disable_credit_sale'),
            'can_take_payments' => $user->can('sell.payments'),
            'can_return' => $user->can('access_sell_return'),
            'can_close_register' => $user->can('close_cash_register'),
            'can_add_customer' => $user->can('customer.create'),
            'can_edit_customer' => $user->can('customer.update'),
            'rewards' => (int) $business->enable_rp === 1 ? [
                'name' => $business->rp_name ?: 'Points',
                'amount_per_point' => (string) $business->redeem_amount_per_unit_rp,
                'min_redeem_points' => $business->min_redeem_point === null ? null : (int) $business->min_redeem_point,
                'max_redeem_points' => $business->max_redeem_point === null ? null : (int) $business->max_redeem_point,
                'min_order_total' => (string) $business->min_order_total_for_redeem,
            ] : null,
        ];
    }

    private function customers(int $businessId, int $locationId): array
    {
        $query = Contact::where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->where('contact_status', 'active');

        [$dues, $duesHere] = $this->customerDues($businessId, $locationId);

        return $query->get([
            'id', 'name', 'supplier_business_name', 'mobile', 'email', 'address_line_1', 'is_default', 'credit_limit', 'balance',
            'pay_term_number', 'pay_term_type', 'total_rp',
        ])->map(fn ($contact) => [
            'id' => $contact->id,
            'name' => $contact->name,
            'business_name' => $contact->supplier_business_name,
            'mobile' => $contact->mobile,
            'email' => $contact->email,
            'address' => $contact->address_line_1,
            'advance' => (string) ($contact->balance ?? 0),
            'is_default' => (int) $contact->is_default,
            'credit_limit' => $contact->credit_limit === null ? null : (string) $contact->credit_limit,
            'amount_due' => $dues[$contact->id] ?? '0',
            'amount_due_here' => $duesHere[$contact->id] ?? '0',
            'pay_term_number' => $contact->pay_term_number,
            'pay_term_type' => $contact->pay_term_type,
            'reward_points' => (int) ($contact->total_rp ?? 0),
        ])->all();
    }

    private function removedCustomers(int $businessId, ?Carbon $since): array
    {
        if (! $since) {
            return [];
        }

        return Contact::withTrashed()
            ->where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->where(fn ($query) => $query->where('contact_status', '!=', 'active')->orWhereNotNull('deleted_at'))
            ->where('updated_at', '>', $since)
            ->pluck('id')
            ->all();
    }

    private function receiptLayout(BusinessLocation $location, string $businessName): array
    {
        $layout = InvoiceLayout::where('business_id', $location->business_id)
            ->find($location->invoice_layout_id);

        $show = function (string $flag) use ($layout): bool {
            if (! $layout || $layout->{$flag} === null) {
                return true;
            }

            return (int) $layout->{$flag} === 1;
        };

        $display = '';
        if ($show('show_business_name')) {
            $display = $businessName;
        }
        if ($show('show_location_name') && $location->name) {
            $display = $display === '' ? $location->name : $display.', '.$location->name;
        }

        $address = [];
        foreach ([
            'show_landmark' => 'landmark',
            'show_city' => 'city',
            'show_state' => 'state',
            'show_zip_code' => 'zip_code',
            'show_country' => 'country',
        ] as $flag => $column) {
            if ($show($flag) && ! empty($location->{$column})) {
                $address[] = $location->{$column};
            }
        }

        $contact = [];
        if ($show('show_mobile_number') && ! empty($location->mobile)) {
            $contact[] = $location->mobile;
        }
        if (! empty($location->email)) {
            $contact[] = $location->email;
        }

        $subheadings = [];
        if ($layout) {
            foreach (['sub_heading_line1', 'sub_heading_line2', 'sub_heading_line3', 'sub_heading_line4', 'sub_heading_line5'] as $line) {
                $value = trim((string) $layout->{$line});
                if ($value !== '') {
                    $subheadings[] = $value;
                }
            }
        }

        return [
            'display_name' => $display,
            'address' => implode(', ', $address),
            'contact' => implode(' · ', $contact),
            'header_text' => $layout->header_text ?? '',
            'footer_text' => $layout->footer_text ?? '',
            'invoice_heading' => ($layout && trim((string) $layout->invoice_heading) !== '')
                ? $layout->invoice_heading
                : 'Invoice',
            'subheadings' => $subheadings,
            'show_customer' => $show('show_customer'),
        ];
    }

    private function paymentMethods($location, int $businessId): array
    {
        $types = $this->util->payment_types($location, false, $businessId);
        $rows = [];
        foreach ($types as $id => $label) {
            $rows[] = ['id' => $id, 'label' => $label];
        }

        return $rows;
    }

    private function sales(int $businessId, int $locationId, ?Carbon $since): array
    {
        $query = Transaction::where('business_id', $businessId)
            ->where('location_id', $locationId)
            ->where('type', 'sell')
            ->where('status', 'final');

        if ($since) {
            $query->where(function ($q) use ($since) {
                $q->where('transactions.updated_at', '>', $since)
                    ->orWhereExists(function ($payments) use ($since) {
                        $payments->select(DB::raw(1))
                            ->from('transaction_payments')
                            ->whereColumn('transaction_payments.transaction_id', 'transactions.id')
                            ->where('transaction_payments.updated_at', '>', $since);
                    });
            });
        } else {
            $query->where(function ($q) {
                $q->where('transaction_date', '>=', now()->subDays(90))
                    ->orWhere('payment_status', '!=', 'paid');
            });
        }

        return $query->select([
            'transactions.id',
            'transactions.invoice_no',
            'transactions.contact_id',
            'transactions.final_total',
            'transactions.payment_status',
            'transactions.transaction_date',
        ])->selectRaw(
            '(SELECT COALESCE(SUM(CASE WHEN is_return = 1 THEN -amount ELSE amount END), 0)
              FROM transaction_payments
              WHERE transaction_payments.transaction_id = transactions.id) as total_paid'
        )->selectRaw(
            "(SELECT COALESCE(SUM(r.final_total), 0) FROM transactions r
              WHERE r.return_parent_id = transactions.id AND r.type = 'sell_return') as total_returned"
        )->get()->map(fn ($sale) => [
            'id' => $sale->id,
            'invoice_no' => $sale->invoice_no,
            'contact_id' => $sale->contact_id,
            'final_total' => (string) $sale->final_total,
            'total_paid' => (string) $sale->total_paid,
            'total_returned' => (string) $sale->total_returned,
            'payment_status' => $sale->payment_status,
            'transaction_date' => (string) $sale->transaction_date,
        ])->all();
    }

    public function sale(User $user, int $transactionId): array
    {
        auth()->setUser($user);
        $sale = Transaction::where('business_id', $user->business_id)
            ->where('type', 'sell')
            ->findOrFail($transactionId);

        if (! User::can_access_this_location($sale->location_id, $user->business_id)) {
            abort(403, 'Location is not allowed');
        }

        $lines = TransactionSellLine::where('transaction_id', $sale->id)
            ->whereNull('parent_sell_line_id')
            ->with(['product', 'variations'])
            ->get()
            ->map(fn ($line) => [
                'sell_line_id' => $line->id,
                'variation_id' => $line->variation_id,
                'name' => $line->product->name ?? 'Item',
                'variation_name' => $line->variations?->name ?? '',
                'quantity' => (string) $line->quantity,
                'quantity_returned' => (string) $line->quantity_returned,
                'unit_price' => (string) $line->unit_price_inc_tax,
            ])->all();

        $returned = (float) Transaction::where('business_id', $user->business_id)
            ->where('type', 'sell_return')
            ->where('return_parent_id', $sale->id)
            ->sum('final_total');

        $payments = TransactionPayment::where('transaction_id', $sale->id)
            ->get(['method', 'amount', 'is_return'])
            ->map(fn ($payment) => [
                'method' => $payment->method,
                'amount' => (string) $payment->amount,
                'is_return' => (int) $payment->is_return,
            ])->all();

        $place = DB::table('cashier_sale_locations')->where('transaction_id', $sale->id)->first();

        $paid = 0;
        foreach ($payments as $payment) {
            $paid += $payment['is_return'] ? -((float) $payment['amount']) : (float) $payment['amount'];
        }

        return [
            'id' => $sale->id,
            'invoice_no' => $sale->invoice_no,
            'contact_id' => $sale->contact_id,
            'final_total' => (string) $sale->final_total,
            'total_paid' => (string) $paid,
            'total_returned' => (string) $returned,
            'discount_type' => $sale->discount_type,
            'discount_amount' => (string) $sale->discount_amount,
            'rp_redeemed_amount' => (string) $sale->rp_redeemed_amount,
            'payment_status' => $sale->payment_status,
            'transaction_date' => (string) $sale->transaction_date,
            'lines' => $lines,
            'payments' => $payments,
            'latitude' => $place->latitude ?? null,
            'longitude' => $place->longitude ?? null,
            'accuracy' => $place->accuracy ?? null,
        ];
    }

    /**
     * Balance owed on unpaid sales, for the whole business and for this shop.
     */
    private function customerDues(int $businessId, int $locationId): array
    {
        $rows = DB::select(
            'SELECT t.contact_id AS contact_id, t.location_id AS location_id,
                SUM(t.final_total - COALESCE(p.paid, 0) - COALESCE(r.returned, 0)) AS due
             FROM transactions t
             LEFT JOIN (
                SELECT transaction_id,
                    SUM(CASE WHEN is_return = 1 THEN -amount ELSE amount END) AS paid
                FROM transaction_payments
                WHERE business_id = ?
                GROUP BY transaction_id
             ) p ON p.transaction_id = t.id
             LEFT JOIN (
                SELECT return_parent_id, SUM(final_total) AS returned
                FROM transactions
                WHERE business_id = ? AND type = ?
                GROUP BY return_parent_id
             ) r ON r.return_parent_id = t.id
             WHERE t.business_id = ? AND t.type = ? AND t.status = ? AND t.payment_status != ?
             GROUP BY t.contact_id, t.location_id',
            [$businessId, $businessId, 'sell_return', $businessId, 'sell', 'final', 'paid']
        );

        $dues = [];
        $here = [];
        foreach ($rows as $row) {
            $contactId = (int) $row->contact_id;
            $dues[$contactId] = ($dues[$contactId] ?? 0) + (float) $row->due;
            if ((int) $row->location_id === $locationId) {
                $here[$contactId] = ($here[$contactId] ?? 0) + (float) $row->due;
            }
        }

        return [
            array_map(fn ($due) => (string) max(0, round($due, 4)), $dues),
            array_map(fn ($due) => (string) max(0, round($due, 4)), $here),
        ];
    }
}
