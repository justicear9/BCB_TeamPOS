<?php

namespace Modules\Cashier\Services;

use App\BusinessLocation;
use App\CashRegister;
use App\CashRegisterTransaction;
use App\Transaction;
use App\User;
use App\Utils\CashRegisterUtil;
use Illuminate\Support\Facades\DB;
use Modules\Cashier\Support\CashierContext;

/**
 * The cashier's TeamPOS cash register (the drawer). One open register per
 * user, the same rule the web POS uses.
 */
class CashierRegister
{
    public function __construct(private CashRegisterUtil $cashRegisterUtil)
    {
    }

    public function current(User $user): ?CashRegister
    {
        return CashRegister::where('business_id', $user->business_id)
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->first();
    }

    public function summary(User $user): array
    {
        CashierContext::bind($user);
        $register = $this->current($user);
        if (! $register) {
            return ['open' => false];
        }

        $details = $this->cashRegisterUtil->getRegisterDetails($register->id);
        $expectedCash = $this->expectedCash($details);

        $methods = [];
        foreach (DB::table('cash_register_transactions')
            ->where('cash_register_id', $register->id)
            ->whereIn('transaction_type', ['sell', 'refund'])
            ->select('pay_method', DB::raw("SUM(IF(type = 'credit', amount, -amount)) as total"))
            ->groupBy('pay_method')
            ->get() as $row) {
            $methods[] = ['method' => $row->pay_method, 'total' => (string) round((float) $row->total, 4)];
        }

        return [
            'open' => true,
            'id' => $register->id,
            'location_id' => $register->location_id,
            'location_name' => $details->location_name,
            'opened_at' => (string) $register->created_at,
            'opening_cash' => (string) round((float) $details->cash_in_hand, 4),
            'total_sales' => (string) round((float) $details->total_sale, 4),
            'total_refunds' => (string) round((float) $details->total_refund, 4),
            'expected_cash' => (string) round($expectedCash, 4),
            'by_method' => $methods,
        ];
    }

    public function open(User $user, int $locationId, float $openingCash): CashRegister
    {
        if (! User::can_access_this_location($locationId, $user->business_id)) {
            abort(403, 'Location is not allowed');
        }
        BusinessLocation::where('business_id', $user->business_id)->findOrFail($locationId);

        return DB::transaction(function () use ($user, $locationId, $openingCash) {
            User::where('id', $user->id)->lockForUpdate()->first();
                if ($this->current($user)) {
                abort(422, 'Your register is already open');
            }

            $register = CashRegister::create([
                'business_id' => $user->business_id,
                'user_id' => $user->id,
                'status' => 'open',
                'location_id' => $locationId,
                'created_at' => now()->format('Y-m-d H:i:00'),
            ]);

            if ($openingCash > 0) {
                $register->cash_register_transactions()->create([
                    'amount' => $openingCash,
                    'pay_method' => 'cash',
                    'type' => 'credit',
                    'transaction_type' => 'initial',
                ]);
            }

            return $register;
        });
    }

    public function close(User $user, float $closingCash, ?string $note): array
    {
        if (! $user->can('close_cash_register')) {
            abort(403, 'You are not allowed to close the register');
        }

        $summary = $this->summary($user);
        if (! $summary['open']) {
            abort(422, 'Your register is not open');
        }

        $cardSlips = CashRegisterTransaction::where('cash_register_id', $summary['id'])->where('pay_method', 'card')->count();
        $cheques = CashRegisterTransaction::where('cash_register_id', $summary['id'])->where('pay_method', 'cheque')->count();

        CashRegister::where('id', $summary['id'])->update([
            'status' => 'close',
            'closed_at' => now()->format('Y-m-d H:i:s'),
            'closing_amount' => $closingCash,
            'total_card_slips' => $cardSlips,
            'total_cheques' => $cheques,
            'closing_note' => $note,
        ]);

        return array_merge($summary, [
            'open' => false,
            'closing_cash' => (string) $closingCash,
            'difference' => (string) round($closingCash - (float) $summary['expected_cash'], 4),
        ]);
    }

    /**
     * Puts a sale's payments in the register that was open when the phone
     * rang it up. A sale synced after its register was closed goes back into
     * that register. With none at all,
     * one opens with no float: the sale already happened, so it must land
     * somewhere.
     */
    public function addSellPayments(User $user, int $locationId, Transaction $transaction, array $payments): void
    {
        $register = $this->registerAt($user, $locationId, (string) $transaction->transaction_date);

        foreach ($payments as $payment) {
            $amount = (float) $payment['amount'];
            if (! empty($payment['is_return'])) {
                $amount = -$amount;
            }
            if ($amount == 0) {
                continue;
            }
            $register->cash_register_transactions()->create([
                'amount' => $amount,
                'pay_method' => $payment['method'],
                'type' => 'credit',
                'transaction_type' => $transaction->type,
                'transaction_id' => $transaction->id,
            ]);
        }
    }

    public function addRefund(User $user, int $locationId, Transaction $return, string $method, float $amount): void
    {
        $register = $this->registerAt($user, $locationId, now()->format('Y-m-d H:i:s'));

        $register->cash_register_transactions()->create([
            'amount' => $amount,
            'pay_method' => $method,
            'type' => 'debit',
            'transaction_type' => 'refund',
            'transaction_id' => $return->id,
        ]);
    }

    private function expectedCash(object $details): float
    {
        return (float) $details->cash_in_hand
            + (float) $details->total_cash
            - (float) $details->total_cash_refund
            - (float) $details->total_cash_expense;
    }

    /**
     * The register that was open for this cashier at $when: the open one if it
     * started by then, otherwise one that was open over that moment.
     */
    private function registerAt(User $user, int $locationId, string $when): CashRegister
    {
        $open = $this->current($user);
        if ($open && (string) $open->getRawOriginal('created_at') <= $when) {
            return $open;
        }

        $past = CashRegister::where('business_id', $user->business_id)
            ->where('user_id', $user->id)
            ->where('status', 'close')
            ->where('created_at', '<=', $when)
            ->where('closed_at', '>=', $when)
            ->orderByDesc('created_at')
            ->first();
        if ($past) {
            return $past;
        }

        return $open ?: CashRegister::create([
            'business_id' => $user->business_id,
            'user_id' => $user->id,
            'status' => 'open',
            'location_id' => $locationId,
            'created_at' => now()->format('Y-m-d H:i:00'),
        ]);
    }
}
