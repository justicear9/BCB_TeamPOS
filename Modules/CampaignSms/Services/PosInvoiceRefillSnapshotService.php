<?php

namespace Modules\CampaignSms\Services;

use App\Product;
use App\Transaction;
use Carbon\Carbon;
use Modules\CampaignSms\Entities\SmsPosInvoiceRefillSnapshot;

class PosInvoiceRefillSnapshotService
{
    public function __construct(
        protected RefillReminderScheduler $scheduler
    ) {
    }

    /**
     * Store refill lines for receipt footer (POS checkbox only).
     *
     * @param  array<int, array<string, mixed>>  $payloadLines
     */
    public function saveForTransaction(Transaction $transaction, array $payloadLines): void
    {
        if ($payloadLines === []) {
            return;
        }

        try {
            SmsPosInvoiceRefillSnapshot::updateOrCreate(
                ['transaction_id' => $transaction->id],
                ['lines' => $payloadLines]
            );
        } catch (\Throwable $e) {
            \Log::warning('CampaignSms: could not save invoice refill snapshot: '.$e->getMessage());
        }
    }

    /**
     * @return array<int, array{product_id:int, product_name:string, interval_days:int, next_reminder_at:string, refill_due_at:string}>
     */
    public function buildLinesFromSale(
        int $businessId,
        Carbon $saleAt,
        array $productLinesWithAddRefill
    ): array {
        $rb = $this->scheduler->reminderDaysBeforeForBusiness($businessId);
        $out = [];

        foreach ($productLinesWithAddRefill as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $interval = max(1, min(3650, (int) ($row['interval_days'] ?? 30)));
            if ($productId <= 0) {
                continue;
            }

            $product = Product::where('business_id', $businessId)->find($productId);
            $name = $product ? (string) $product->name : '';

            $nextReminder = $this->scheduler->computeNextRunFromPurchase($saleAt, $interval, $rb);
            $refillDue = $saleAt->copy()->timezone(config('app.timezone'))->startOfDay()->addDays($interval)->setTime(9, 0, 0);

            $out[] = [
                'product_id' => $productId,
                'product_name' => $name,
                'interval_days' => $interval,
                'next_reminder_at' => $nextReminder->toIso8601String(),
                'refill_due_at' => $refillDue->toIso8601String(),
            ];
        }

        return $out;
    }

    public function findForTransactionId(int $transactionId): ?SmsPosInvoiceRefillSnapshot
    {
        return SmsPosInvoiceRefillSnapshot::where('transaction_id', $transactionId)->first();
    }
}
