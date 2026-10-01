<?php

namespace Modules\Cashier\Services;

use App\Business;
use App\BusinessLocation;
use App\Contact;
use App\Product;
use App\SellingPriceGroup;
use App\Transaction;
use App\User;
use App\Variation;
use App\VariationLocationDetails;
use App\Utils\BusinessUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Cashier\Entities\CashierClientRef;
use Modules\Cashier\Support\CashierContext;

class SaleCreator
{
    /** Offline sales older than this are stamped with the sync time instead. */
    public const MAX_AGE_DAYS = 60;

    public function __construct(
        private TransactionUtil $transactionUtil,
        private ProductUtil $productUtil,
        private BusinessUtil $businessUtil,
        private CashierRegister $register
    ) {
    }

    public function create(User $user, array $payload): array
    {
        CashierContext::bind($user);

        if (! $user->can('sell.create')) {
            abort(403, 'Missing sell.create');
        }

        if ($replay = $this->replay($user, $payload['client_uuid'])) {
            return $replay;
        }

        if (! User::can_access_this_location($payload['location_id'], $user->business_id)) {
            abort(403, 'Location is not allowed');
        }

        try {
            return DB::transaction(fn () => $this->store($user, $payload));
        } catch (QueryException $e) {
            if (CashierClientRef::isDuplicate($e) && ($replay = $this->replay($user, $payload['client_uuid']))) {
                return $replay;
            }
            throw $e;
        }
    }

    private function replay(User $user, string $clientUuid): ?array
    {
        $existing = CashierClientRef::where('business_id', $user->business_id)
            ->where('client_uuid', $clientUuid)
            ->where('entity_type', 'sale')
            ->first();

        if (! $existing) {
            return null;
        }

        $transaction = Transaction::where('business_id', $user->business_id)
            ->where('id', $existing->entity_id)
            ->firstOrFail();

        if (! User::can_access_this_location($transaction->location_id, $user->business_id)) {
            abort(403, 'Location is not allowed');
        }

        return $this->present($existing, $transaction, true);
    }

    private function store(User $user, array $payload): array
    {
        $businessId = $user->business_id;
        $business = Business::findOrFail($businessId);
        $location = BusinessLocation::where('business_id', $businessId)
            ->findOrFail($payload['location_id']);
        $contact = Contact::where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->findOrFail($payload['contact_id']);

        $priceGroupId = $this->priceGroup($user, $location, $payload);
        $lines = [];
        foreach ($payload['products'] as $line) {
            $lines[] = $this->line($user, $location, $line, $priceGroupId);
        }

        $this->ensureStock($location, $lines);

        $discount = $this->discount($user, $payload);
        $invoiceTotal = $this->productUtil->calculateInvoiceTotal($lines, null, $discount, false);

        [$points, $pointsAmount] = $this->redeem($business, $contact, $payload, $invoiceTotal['final_total']);
        $invoiceTotal['final_total'] = round($invoiceTotal['final_total'] - $pointsAmount, 4);
        if ($invoiceTotal['final_total'] < 0) {
            abort(422, 'The discount is more than the sale total');
        }

        [$transactionDate, $dateNote] = $this->transactionDate($business, $payload['transaction_date']);

        $input = [
            'location_id' => $location->id,
            'contact_id' => $contact->id,
            'transaction_date' => $transactionDate,
            'status' => 'final',
            'source' => 'cashier_app',
            'discount_type' => $discount['discount_type'],
            'discount_amount' => $discount['discount_amount'],
            'final_total' => $invoiceTotal['final_total'],
            'is_created_from_api' => 1,
            'selling_price_group_id' => ($priceGroupId ?? (int) $location->selling_price_group_id) ?: null,
            'rp_redeemed' => $points,
            'rp_redeemed_amount' => $pointsAmount,
            'staff_note' => $dateNote,
            'products' => $lines,
        ];

        $transaction = $this->transactionUtil->createSellTransaction(
            $businessId,
            $input,
            $invoiceTotal,
            $user->id,
            false
        );

        $this->transactionUtil->createOrUpdateSellLines(
            $transaction,
            $lines,
            $location->id,
            false,
            null,
            [],
            false
        );

        [$payments, $paid] = $this->payments($location, $businessId, $payload['payments']);

        $total = (float) $invoiceTotal['final_total'];
        if ($paid - $total > 0.009) {
            abort(422, 'Payments are more than the sale total');
        }

        if ($total - $paid > 0.009) {
            if (self::restricted($user, 'disable_credit_sale')) {
                abort(422, 'You are not allowed to sell on credit');
            }
            $limit = $this->transactionUtil->isCustomerCreditLimitExeeded([
                'status' => 'final',
                'contact_id' => $contact->id,
                'final_total' => $total,
                'payment' => $paid > 0 ? [['amount' => $paid]] : [],
            ], null, false);
            if ($limit !== false) {
                abort(422, 'This customer cannot take that balance on credit');
            }
        }

        $this->transactionUtil->createOrUpdatePaymentLines(
            $transaction,
            $payments,
            $businessId,
            $user->id,
            false
        );

        if ($paid > 0.009) {
            $this->register->addSellPayments($user, $location->id, $transaction, $payments);
        }

        foreach ($lines as $line) {
            if ($line['enable_stock']) {
                $this->productUtil->decreaseProductQuantity(
                    $line['product_id'],
                    $line['variation_id'],
                    $location->id,
                    $line['quantity']
                );
            }
            if ($line['product_type'] === 'combo' && ! empty($line['combo'])) {
                $this->productUtil->decreaseProductQuantityCombo($line['combo'], $location->id);
            }
        }

        $transaction->refresh();
        $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);

        if ((int) $business->enable_rp === 1) {
            $this->transactionUtil->updateCustomerRewardPoints($contact->id, $transaction->rp_earned, 0, $points);
        }

        $details = $this->businessUtil->getDetails($businessId);
        $posSettings = empty($details->pos_settings)
            ? $this->businessUtil->defaultPosSettings()
            : json_decode($details->pos_settings, true);
        // The sale is already finished on the device. Purchase mapping must not reject it.
        $posSettings['allow_overselling'] = 1;
        $this->transactionUtil->mapPurchaseSell([
            'id' => $businessId,
            'accounting_method' => $business->accounting_method,
            'location_id' => $location->id,
            'pos_settings' => $posSettings,
        ], $transaction->sell_lines, 'purchase');

        if (isset($payload['latitude'], $payload['longitude']) && is_numeric($payload['latitude']) && is_numeric($payload['longitude'])) {
            DB::table('cashier_sale_locations')->insert([
                'business_id' => $businessId,
                'transaction_id' => $transaction->id,
                'latitude' => $payload['latitude'],
                'longitude' => $payload['longitude'],
                'accuracy' => isset($payload['accuracy']) && is_numeric($payload['accuracy']) ? $payload['accuracy'] : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $ref = CashierClientRef::create([
            'business_id' => $businessId,
            'client_uuid' => $payload['client_uuid'],
            'entity_type' => 'sale',
            'entity_id' => $transaction->id,
            'device_ref' => $payload['device_ref'],
        ]);

        return $this->present($ref, $transaction->fresh(), false);
    }

    /**
     * Stock never goes below zero. Rows are locked so two phones selling the
     * last units cannot both pass: the first to sync gets them, the other is
     * refused and nothing from it is saved.
     */
    private function ensureStock(BusinessLocation $location, array $lines): void
    {
        $needed = [];
        foreach ($lines as $line) {
            if ((int) $line['enable_stock'] === 1) {
                $needed[$line['variation_id']] = ($needed[$line['variation_id']] ?? 0) + $line['quantity'];
            }
            foreach ($line['combo'] ?? [] as $item) {
                $needed[$item['variation_id']] = ($needed[$item['variation_id']] ?? 0) + $item['quantity'];
            }
        }
        if (! $needed) {
            return;
        }

        $onHand = VariationLocationDetails::where('location_id', $location->id)
            ->whereIn('variation_id', array_keys($needed))
            ->lockForUpdate()
            ->pluck('qty_available', 'variation_id');

        foreach ($needed as $variationId => $quantity) {
            $available = (float) ($onHand[$variationId] ?? 0);
            if ($quantity - $available > 0.0001) {
                $variation = Variation::with('product')->find($variationId);
                $name = $variation->product->name ?? 'An item';
                abort(422, sprintf(
                    'Not enough %s at %s: %s left, this sale needs %s. The sale was not saved.',
                    $name,
                    $location->name,
                    rtrim(rtrim(number_format(max(0, $available), 2, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.')
                ));
            }
        }
    }

    /**
     * The price group the sale was rung up in. Null when the device did not say,
     * which keeps the location's own group, as sales did before groups were offered.
     */
    private function priceGroup(User $user, BusinessLocation $location, array $payload): ?int
    {
        if (! isset($payload['selling_price_group_id'])) {
            return null;
        }

        $groupId = (int) $payload['selling_price_group_id'];
        if ($groupId === 0) {
            if (empty($location->selling_price_group_id) || $user->can('access_default_selling_price')) {
                return 0;
            }
            abort(422, 'You are not allowed to sell at the default price');
        }

        $exists = SellingPriceGroup::where('business_id', $location->business_id)->active()->whereKey($groupId)->exists();
        if (! $exists || ! $user->can('selling_price_group.'.$groupId)) {
            abort(422, 'That price group is not available. Sync, then ring the sale up again.');
        }

        return $groupId;
    }

    private function line(User $user, BusinessLocation $location, array $line, ?int $priceGroupId): array
    {
        $product = Product::where('business_id', $location->business_id)
            ->with(['variations', 'product_tax'])
            ->find($line['product_id']);

        if (! $product) {
            abort(422, 'A product on this sale no longer exists');
        }
        if ((int) $product->is_inactive === 1 || (int) $product->not_for_selling === 1 || $product->type === 'modifier') {
            abort(422, "{$product->name} is not for sale");
        }
        if (! $product->product_locations()->where('product_locations.location_id', $location->id)->exists()) {
            abort(422, "{$product->name} is not sold at {$location->name}");
        }

        $variation = $product->variations->firstWhere('id', (int) $line['variation_id']);
        if (! $variation) {
            abort(422, 'Variation is not on this product');
        }

        $listPrice = round(self::listPrice($this->productUtil, $variation, $location, $product->tax, $priceGroupId), 4);
        $devicePrice = round((float) $line['unit_price'], 4);
        $price = $listPrice;
        if (abs($devicePrice - $listPrice) > 0.009) {
            if (empty($line['price_override'])) {
                abort(422, sprintf('%s now sells for %s. Sync, then ring it up again.', $product->name, number_format($listPrice, 2)));
            }
            if (! $user->can('edit_product_price_from_pos_screen')) {
                abort(422, 'You are not allowed to change prices');
            }
            $price = $devicePrice;
        }

        $taxRate = (float) ($product->product_tax->amount ?? 0);
        $priceExcTax = $taxRate > 0 ? $this->productUtil->calc_percentage_base($price, $taxRate) : $price;

        $quantity = (float) $line['quantity'];
        $row = [
            'product_id' => $product->id,
            'variation_id' => $variation->id,
            'product_type' => $product->type,
            'unit_price' => $priceExcTax,
            'line_discount_type' => 'fixed',
            'line_discount_amount' => 0,
            'tax_id' => $product->tax ?: null,
            'item_tax' => round($price - $priceExcTax, 4),
            'sell_line_note' => null,
            'enable_stock' => $product->enable_stock,
            'quantity' => $quantity,
            'product_unit_id' => $product->unit_id,
            'sub_unit_id' => null,
            'unit_price_inc_tax' => $price,
            'base_unit_multiplier' => 1,
        ];

        if ($product->type === 'combo') {
            $row['combo'] = [];
            foreach ($this->productUtil->calculateComboDetails($location->id, $variation->combo_variations ?? []) as $item) {
                if ((int) $item['enable_stock'] !== 1) {
                    continue;
                }
                $row['combo'][] = [
                    'product_id' => $item['product_id'],
                    'variation_id' => $item['variation_id'],
                    'quantity' => $item['qty_required'] * $quantity,
                ];
            }
        }

        return $row;
    }

    /**
     * The price the shop sells this variation for, tax included. A null group
     * means the location's own selling price group, 0 the default price. Falls
     * back to the default price when the group has no price, or a zero price,
     * for the variation.
     */
    public static function listPrice(ProductUtil $productUtil, $variation, BusinessLocation $location, $taxId, ?int $priceGroupId = null): float
    {
        $groupId = $priceGroupId ?? (int) $location->selling_price_group_id;
        if ($groupId > 0) {
            $group = $productUtil->getVariationGroupPrice($variation->id, $groupId, $taxId);
            if ((float) $group['price_inc_tax'] > 0) {
                return (float) $group['price_inc_tax'];
            }
        }

        return (float) $variation->sell_price_inc_tax;
    }

    private function discount(User $user, array $payload): array
    {
        $amount = round((float) ($payload['discount_amount'] ?? 0), 4);
        $type = ($payload['discount_type'] ?? 'fixed') === 'percentage' ? 'percentage' : 'fixed';
        if ($amount <= 0) {
            return ['discount_type' => 'fixed', 'discount_amount' => 0];
        }
        if (self::restricted($user, 'disable_discount')) {
            abort(422, 'You are not allowed to give discounts');
        }
        if ($type === 'percentage' && $amount > 100) {
            abort(422, 'A discount cannot be more than 100%');
        }

        return ['discount_type' => $type, 'discount_amount' => $amount];
    }

    private function redeem(Business $business, Contact $contact, array $payload, float $total): array
    {
        $points = (int) ($payload['rp_redeemed'] ?? 0);
        if ($points <= 0) {
            return [0, 0.0];
        }
        if ((int) $business->enable_rp !== 1) {
            abort(422, 'Reward points are turned off');
        }

        $allowed = $this->transactionUtil->getRewardRedeemDetails($business->id, $contact->id);
        if ($points > (int) $allowed['points']) {
            abort(422, 'This customer does not have that many points to use');
        }
        if (! empty($business->min_order_total_for_redeem) && $total < (float) $business->min_order_total_for_redeem) {
            abort(422, 'The sale is too small to use points');
        }

        return [$points, round($points * (float) $business->redeem_amount_per_unit_rp, 4)];
    }

    /**
     * The phone sends its local time with an offset. Store it in the business
     * timezone, and fall back to the sync time when the phone clock is clearly wrong.
     */
    private function transactionDate(Business $business, string $raw): array
    {
        $zone = $business->time_zone ?: config('app.timezone');
        $now = Carbon::now($zone);

        try {
            $when = Carbon::parse($raw, $zone)->setTimezone($zone);
        } catch (\Throwable $e) {
            $when = null;
        }

        if (! $when || $when->greaterThan($now->copy()->addMinutes(10)) || $when->lessThan($now->copy()->subDays(self::MAX_AGE_DAYS))) {
            return [$now->toDateTimeString(), "Phone clock said {$raw}. Saved with the sync time."];
        }

        return [$when->toDateTimeString(), null];
    }

    private function payments(BusinessLocation $location, int $businessId, array $rows): array
    {
        $allowed = array_keys($this->businessUtil->payment_types($location, false, $businessId));
        $payments = [];
        $paid = 0.0;
        $change = 0.0;
        foreach ($rows as $payment) {
            $method = (string) $payment['method'];
            if (! in_array($method, $allowed, true)) {
                abort(422, 'Payment method is not available at this location');
            }
            $amount = round((float) $payment['amount'], 4);
            $tendered = round((float) ($payment['tendered'] ?? $amount), 4);
            if ($amount < 0 || $tendered + 0.0001 < $amount) {
                abort(422, 'Payment amount is not valid');
            }
            $stored = $method === 'cash' && $tendered > $amount ? $tendered : $amount;
            if ($method === 'cash') {
                $change += max(0, $tendered - $amount);
            }
            if ($stored > 0) {
                $payments[] = $this->paymentLine($method, $stored);
                $paid += $amount;
            }
        }

        if ($change > 0.009) {
            $payments[] = $this->paymentLine('cash', round($change, 4), 1);
        }

        return [$payments, $paid];
    }

    /**
     * TeamPOS "disable_*" permissions take a feature away unless the user is an admin.
     */
    public static function restricted(User $user, string $permission): bool
    {
        return $user->can($permission) && ! $user->can('superadmin') && ! $user->can('admin');
    }

    private function present(CashierClientRef $ref, Transaction $transaction, bool $replayed): array
    {
        return [
            'client_uuid' => $ref->client_uuid,
            'entity_type' => 'sale',
            'entity_id' => $transaction->id,
            'invoice_no' => $transaction->invoice_no,
            'final_total' => $transaction->final_total,
            'transaction_date' => (string) $transaction->transaction_date,
            'rp_earned' => (int) $transaction->rp_earned,
            'device_ref' => $ref->device_ref,
            'replayed' => $replayed,
        ];
    }

    private function paymentLine(string $method, float $amount, int $isReturn = 0): array
    {
        $line = [
            'method' => $method,
            'amount' => $amount,
            'note' => '',
            'card_transaction_number' => '',
            'card_number' => '',
            'card_type' => '',
            'card_holder_name' => '',
            'card_month' => '',
            'card_year' => '',
            'card_security' => '',
            'cheque_number' => '',
            'bank_account_number' => '',
            'is_return' => $isReturn,
            'transaction_no' => '',
        ];
        for ($i = 1; $i < 8; $i++) {
            $line['transaction_no_'.$i] = '';
        }

        return $line;
    }
}
