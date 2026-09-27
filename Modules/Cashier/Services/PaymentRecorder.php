<?php

namespace Modules\Cashier\Services;

use App\BusinessLocation;
use App\Contact;
use App\Events\TransactionPaymentAdded;
use App\Transaction;
use App\TransactionPayment;
use App\User;
use App\Utils\TransactionUtil;
use App\Utils\Util;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Cashier\Entities\CashierClientRef;
use Modules\Cashier\Support\CashierContext;

class PaymentRecorder
{
    public function __construct(
        private TransactionUtil $transactionUtil,
        private Util $util
    ) {
    }

    public function add(User $user, array $payload): array
    {
        CashierContext::bind($user);
        $this->authorize($user);

        if ($replay = $this->replayPayment($user, $payload['client_uuid'])) {
            return $replay;
        }

        try {
            return DB::transaction(fn () => $this->storePayment($user, $payload));
        } catch (QueryException $e) {
            if (CashierClientRef::isDuplicate($e) && ($replay = $this->replayPayment($user, $payload['client_uuid']))) {
                return $replay;
            }
            throw $e;
        }
    }

    public function advance(User $user, array $payload): array
    {
        CashierContext::bind($user);
        $this->authorize($user);

        if ($replay = $this->replayAdvance($user, $payload['client_uuid'])) {
            return $replay;
        }

        $location = BusinessLocation::where('business_id', $user->business_id)->find((int) ($payload['location_id'] ?? 0));
        if (! $location || ! User::can_access_this_location($location->id, $user->business_id)) {
            abort(403, 'Location is not allowed');
        }

        try {
            return DB::transaction(fn () => $this->storeAdvance($user, $location, $payload));
        } catch (QueryException $e) {
            if (CashierClientRef::isDuplicate($e) && ($replay = $this->replayAdvance($user, $payload['client_uuid']))) {
                return $replay;
            }
            throw $e;
        }
    }

    private function authorize(User $user): void
    {
        if (! $user->can('sell.payments')) {
            abort(403, 'You are not allowed to take payments');
        }
    }

    private function storePayment(User $user, array $payload): array
    {
        $transaction = $this->transaction($user, $payload);
        $amount = round((float) $payload['amount'], 4);
        if ($amount <= 0) {
            abort(422, 'Enter the amount received');
        }

        $allowed = array_keys($this->util->payment_types($transaction->location_id, false, $user->business_id));
        if (! in_array($payload['method'], $allowed, true)) {
            abort(422, 'Payment method is not available at this location');
        }

        $due = round((float) $transaction->final_total - (float) $this->transactionUtil->getTotalPaid($transaction->id), 4);
        if ($amount - $due > 0.009) {
            abort(422, 'That amount is more than the balance due');
        }

        $refCount = $this->transactionUtil->setAndGetReferenceCount('sell_payment', $user->business_id);
        $payment = new TransactionPayment([
            'amount' => $amount,
            'method' => $payload['method'],
            'business_id' => $transaction->business_id,
            'is_return' => 0,
            'paid_on' => now()->toDateTimeString(),
            'created_by' => $user->id,
            'payment_for' => $transaction->contact_id,
            'payment_ref_no' => $this->transactionUtil->generateReferenceNumber(
                'sell_payment',
                $refCount,
                $user->business_id
            ),
        ]);
        $transaction->payment_lines()->save($payment);
        event(new TransactionPaymentAdded($payment, [
            'amount' => $amount,
            'method' => $payload['method'],
            'transaction_type' => $transaction->type,
        ]));
        $this->transactionUtil->updatePaymentStatus($transaction->id, $transaction->final_total);

        $ref = CashierClientRef::create([
            'business_id' => $user->business_id,
            'client_uuid' => $payload['client_uuid'],
            'entity_type' => 'payment',
            'entity_id' => $transaction->id,
            'device_ref' => $payload['device_ref'] ?? '',
        ]);

        return $this->present($ref, $transaction->fresh(), false);
    }

    /**
     * Money a customer hands over at a shop settles their oldest unpaid sales
     * across the business first, the same as "Pay" on the TeamPOS contact page.
     * Anything left stays on the customer as an advance.
     */
    private function storeAdvance(User $user, BusinessLocation $location, array $payload): array
    {
        $contact = Contact::where('business_id', $user->business_id)
            ->whereIn('type', ['customer', 'both'])
            ->lockForUpdate()
            ->findOrFail($payload['contact_id']);
        $amount = round((float) $payload['amount'], 4);
        if ($amount <= 0) {
            abort(422, 'Enter the amount received');
        }

        $allowed = array_keys($this->util->payment_types($location, false, $user->business_id));
        if (! in_array($payload['method'], $allowed, true)) {
            abort(422, 'Payment method is not available at this location');
        }

        $request = Request::create('/', 'POST', [
            'contact_id' => $contact->id,
            'amount' => $amount,
            'method' => $payload['method'],
            'paid_on' => now()->toDateTimeString(),
        ]);
        $payment = $this->transactionUtil->payContact($request, false);

        $ref = CashierClientRef::create([
            'business_id' => $user->business_id,
            'client_uuid' => $payload['client_uuid'],
            'entity_type' => 'advance',
            'entity_id' => $payment->id,
            'device_ref' => $payload['device_ref'] ?? '',
        ]);

        return $this->presentAdvance($ref, $payment->fresh(), false);
    }

    private function replayPayment(User $user, string $clientUuid): ?array
    {
        $existing = CashierClientRef::where('business_id', $user->business_id)
            ->where('client_uuid', $clientUuid)
            ->where('entity_type', 'payment')
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

    private function replayAdvance(User $user, string $clientUuid): ?array
    {
        $existing = CashierClientRef::where('business_id', $user->business_id)
            ->where('client_uuid', $clientUuid)
            ->where('entity_type', 'advance')
            ->first();
        if (! $existing) {
            return null;
        }

        $payment = TransactionPayment::where('business_id', $user->business_id)
            ->findOrFail($existing->entity_id);

        return $this->presentAdvance($existing, $payment, true);
    }

    private function transaction(User $user, array $payload): Transaction
    {
        $id = $payload['transaction_id'] ?? null;
        if (! $id && ! empty($payload['sale_client_uuid'])) {
            $sale = CashierClientRef::where('business_id', $user->business_id)
                ->where('client_uuid', $payload['sale_client_uuid'])
                ->where('entity_type', 'sale')
                ->first();
            $id = $sale->entity_id ?? null;
        }

        if (! $id) {
            abort(422, 'Sync the sale before adding this payment');
        }

        $transaction = Transaction::where('business_id', $user->business_id)
            ->where('type', 'sell')
            ->lockForUpdate()
            ->findOrFail($id);

        if (! User::can_access_this_location($transaction->location_id, $user->business_id)) {
            abort(403, 'Location is not allowed');
        }

        return $transaction;
    }

    private function present(CashierClientRef $ref, Transaction $transaction, bool $replayed): array
    {
        return [
            'client_uuid' => $ref->client_uuid,
            'entity_type' => 'payment',
            'entity_id' => $transaction->id,
            'transaction_id' => $transaction->id,
            'invoice_no' => $transaction->invoice_no,
            'payment_status' => $transaction->payment_status,
            'total_paid' => (string) $this->transactionUtil->getTotalPaid($transaction->id),
            'final_total' => $transaction->final_total,
            'replayed' => $replayed,
        ];
    }

    private function presentAdvance(CashierClientRef $ref, TransactionPayment $payment, bool $replayed): array
    {
        $contact = Contact::find($payment->payment_for);

        return [
            'client_uuid' => $ref->client_uuid,
            'entity_type' => 'advance',
            'entity_id' => $payment->id,
            'contact_id' => $payment->payment_for,
            'advance' => (string) ($contact->balance ?? 0),
            'replayed' => $replayed,
        ];
    }
}
