<?php

namespace Modules\CampaignSms\Services;

use App\Contact;
use App\TransactionSellLine;
use Carbon\Carbon;
use Modules\CampaignSms\Entities\SmsCampaignSetting;
use Modules\CampaignSms\Entities\SmsRefillReminder;

class RefillReminderScheduler
{
    /**
     * Latest final sale datetime for this product+contact (any variation).
     */
    public function lastPurchaseAt(int $businessId, int $contactId, int $productId): ?Carbon
    {
        $raw = TransactionSellLine::query()
            ->join('transactions as t', 't.id', '=', 'transaction_sell_lines.transaction_id')
            ->where('t.business_id', $businessId)
            ->where('t.contact_id', $contactId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('transaction_sell_lines.product_id', $productId)
            ->max('t.transaction_date');

        if ($raw === null) {
            return null;
        }

        return Carbon::parse($raw);
    }

    public function reminderDaysBeforeForBusiness(int $businessId): int
    {
        $row = SmsCampaignSetting::where('business_id', $businessId)->first();
        $days = $row !== null ? $row->reminder_days_before : null;

        return max(0, (int) ($days ?? 3));
    }

    public function clampReminderBefore(int $intervalDays, int $settingsDays): int
    {
        if ($intervalDays <= 1) {
            return 0;
        }

        return max(0, min($settingsDays, $intervalDays - 1));
    }

    /**
     * First SMS reminder moment: (last purchase + interval) minus reminder window, at 09:00 (or now if overdue).
     */
    public function computeNextRunFromPurchase(Carbon $purchaseAt, int $intervalDays, int $reminderDaysBefore): Carbon
    {
        $rb = $this->clampReminderBefore($intervalDays, $reminderDaysBefore);
        $run = $purchaseAt->copy()
            ->timezone(config('app.timezone'))
            ->startOfDay()
            ->addDays($intervalDays)
            ->subDays($rb)
            ->setTime(9, 0, 0);

        if ($run->lessThanOrEqualTo(Carbon::now())) {
            return Carbon::now();
        }

        return $run;
    }

    /**
     * Next theoretical reminder if nobody repurchases (after a send).
     */
    public function computeNextRunAfterSend(Carbon $sentAt, int $intervalDays, int $reminderDaysBefore): Carbon
    {
        $rb = $this->clampReminderBefore($intervalDays, $reminderDaysBefore);

        return $sentAt->copy()
            ->addDays($intervalDays)
            ->subDays($rb)
            ->setTime(9, 0, 0);
    }

    public function rescheduleAfterPurchase(SmsRefillReminder $reminder, Carbon $saleAt): void
    {
        $contact = $reminder->contact;
        if (! $contact || (int) $contact->is_default === 1) {
            return;
        }

        $rb = $this->reminderDaysBeforeForBusiness($reminder->business_id);
        $reminder->next_run_at = $this->computeNextRunFromPurchase($saleAt, (int) $reminder->interval_days, $rb);
        $reminder->is_active = true;
        $reminder->save();
    }

    /**
     * POS: create a reminder only when checkbox checked and none exists yet.
     */
    public function createFromPosIfMissing(int $businessId, int $contactId, int $productId, Carbon $saleAt, int $intervalDays): void
    {
        $contact = Contact::where('business_id', $businessId)->find($contactId);
        if (! $contact || (int) $contact->is_default === 1) {
            return;
        }

        $exists = SmsRefillReminder::where('business_id', $businessId)
            ->where('contact_id', $contactId)
            ->where('product_id', $productId)
            ->exists();

        if ($exists) {
            return;
        }

        $intervalDays = max(1, min(3650, $intervalDays));
        $rb = $this->reminderDaysBeforeForBusiness($businessId);

        SmsRefillReminder::create([
            'business_id' => $businessId,
            'contact_id' => $contactId,
            'product_id' => $productId,
            'interval_days' => $intervalDays,
            'next_run_at' => $this->computeNextRunFromPurchase($saleAt, $intervalDays, $rb),
            'last_sent_at' => null,
            'template_body' => null,
            'is_active' => true,
        ]);
    }

    /**
     * True if we have not reached (last purchase + interval − reminder offset) yet — do not send SMS.
     */
    public function isTooEarlyToRemind(Carbon $now, Carbon $lastPurchaseAt, int $intervalDays, int $reminderDaysBefore): bool
    {
        $rb = $this->clampReminderBefore($intervalDays, $reminderDaysBefore);
        $firstReminderAt = $lastPurchaseAt->copy()
            ->timezone(config('app.timezone'))
            ->startOfDay()
            ->addDays($intervalDays)
            ->subDays($rb)
            ->setTime(9, 0, 0);

        return $now->lt($firstReminderAt);
    }
}
