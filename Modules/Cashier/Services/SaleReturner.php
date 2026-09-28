<?php

namespace Modules\Cashier\Services;

use App\Transaction;
use App\TransactionPayment;
use App\User;
use App\Utils\TransactionUtil;
use App\Utils\Util;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Cashier\Entities\CashierClientRef;
use Modules\Cashier\Support\CashierContext;

class SaleReturner
{
    public function __construct(
        private TransactionUtil $transactionUtil,
        private Util $util,
        private CashierRegister $register
    ) {
    }

    public function create(User $user, array $payload): array
    {
        CashierContext::bind($user);

        if (! $user->can('access_sell_return')) {
            abort(403, 'You are not allowed to take returns');
        }

        if ($replay = $this->replay($user, $payload['client_uuid'])) {
            return $replay;
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

    private function store(User $user, array $payload): array
    {
        $sale = Transaction::where('business_id', $user->business_id)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->with('sell_lines')
            ->lockForUpdate()
            ->findOrFail($payload['transaction_id']);

        if (! User::can_access_this_location($sale->location_id, $user->business_id)) {
            abort(403, 'Location is not allowed');
        }

        $method = (string) ($payload['refund_method'] ?? 'cash');
        $allowed = array_keys($this->util->payment_types($sale->location_id, false, $user->business_id));
        if (! in_array($method, $allowed, true)) {
            abort(422, 'Refund method is not available at this location');
        }

        $requested = [];
        foreach ($payload['lines'] as $line) {
            $requested[(int) $line['sell_line_id']] = ($requested[(int) $line['sell_line_id']] ?? 0) + (float) $line['quantity'];
        }

        $products = [];
        $adding = false;
        foreach ($sale->sell_lines as $sellLine) {
            if ($sellLine->parent_sell_line_id) {
                continue;
            }
            $already = (float) $sellLine->quantity_returned;
            $more = $requested[$sellLine->id] ?? 0;
            unset($requested[$sellLine->id]);
            if ($more < 0) {
                abort(422, 'Return quantities must be positive');
            }
            if ($already + $more - (float) $sellLine->quantity > 0.0001) {
                abort(422, 'You cannot return more than was sold');
            }
            if ($more > 0) {
                $adding = true;
            }
            if ($already + $more > 0) {
                $products[] = [
                    'sell_line_id' => $sellLine->id,
                    'quantity' => $already + $more,
                    'unit_price_inc_tax' => (float) $sellLine->unit_price_inc_tax,
                ];
            }
        }
        if (! empty($requested)) {
            abort(422, 'An item is not on this sale');
        }
        if (! $adding) {
            abort(422, 'Choose what is coming back');
        }

        $previous = Transaction::where('business_id', $user->business_id)
            ->where('type', 'sell_return')
            ->where('return_parent_id', $sale->id)
            ->first();
        $previousTotal = (float) ($previous->final_total ?? 0);

        $return = $this->transactionUtil->addSellReturn([
            'transaction_id' => $sale->id,
            'products' => $products,
            'discount_type' => 'percentage',
            'discount_amount' => $this->discountPercent($sale),
        ], $user->business_id, $user->id, false);

        $returnTotal = (float) $return->final_total;
        $delta = round($returnTotal - $previousTotal, 4);

        $paid = (float) $this->transactionUtil->getTotalPaid($sale->id);
        $refundedBefore = (float) TransactionPayment::where('transaction_id', $return->id)->sum('amount');
        $owedToCustomer = round($paid - ((float) $sale->final_total - $returnTotal) - $refundedBefore, 4);
        $refund = round(max(0, min($delta, $owedToCustomer)), 4);

        if ($refund > 0) {
            $refCount = $this->transactionUtil->setAndGetReferenceCount('sell_payment', $user->business_id);
            $return->payment_lines()->save(new TransactionPayment([
                'amount' => $refund,
                'method' => $method,
                'business_id' => $user->business_id,
                'is_return' => 0,
                'paid_on' => now()->toDateTimeString(),
                'created_by' => $user->id,
                'payment_for' => $sale->contact_id,
                'payment_ref_no' => $this->transactionUtil->generateReferenceNumber('sell_payment', $refCount, $user->business_id),
            ]));
            $this->register->addRefund($user, $sale->location_id, $return, $method, $refund);
        }
        $this->transactionUtil->updatePaymentStatus($return->id, $return->final_total);

        $ref = CashierClientRef::create([
            'business_id' => $user->business_id,
            'client_uuid' => $payload['client_uuid'],
            'entity_type' => 'return',
            'entity_id' => $return->id,
            'device_ref' => $payload['device_ref'] ?? '',
        ]);

        return $this->present($ref, $return->fresh(), $refund, $delta, false);
    }

    /**
     * The sale discount, as a percentage, so a partial return gets its share of it.
     */
    private function discountPercent(Transaction $sale): float
    {
        $amount = (float) $sale->discount_amount;
        if ($amount <= 0) {
            return 0;
        }
        if ($sale->discount_type === 'percentage') {
            return $amount;
        }
        $before = (float) $sale->total_before_tax;

        return $before > 0 ? round($amount / $before * 100, 6) : 0;
    }

    private function replay(User $user, string $clientUuid): ?array
    {
        $existing = CashierClientRef::where('business_id', $user->business_id)
            ->where('client_uuid', $clientUuid)
            ->where('entity_type', 'return')
            ->first();
        if (! $existing) {
            return null;
        }

        $return = Transaction::where('business_id', $user->business_id)->findOrFail($existing->entity_id);
        if (! User::can_access_this_location($return->location_id, $user->business_id)) {
            abort(403, 'Location is not allowed');
        }

        return $this->present($existing, $return, null, null, true);
    }

    private function present(CashierClientRef $ref, Transaction $return, ?float $refund, ?float $delta, bool $replayed): array
    {
        return [
            'client_uuid' => $ref->client_uuid,
            'entity_type' => 'return',
            'entity_id' => $return->id,
            'invoice_no' => $return->invoice_no,
            'sale_id' => $return->return_parent_id,
            'return_total' => (string) $return->final_total,
            'amount' => $delta === null ? null : (string) $delta,
            'refund' => $refund === null ? null : (string) $refund,
            'replayed' => $replayed,
        ];
    }
}
