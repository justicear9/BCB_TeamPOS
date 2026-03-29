<?php

namespace Modules\CampaignSms\Console;

use App\Business;
use App\Utils\ModuleUtil;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\CampaignSms\Entities\SmsCampaignSetting;
use Modules\CampaignSms\Entities\SmsRefillReminder;
use Modules\CampaignSms\Services\RefillReminderScheduler;
use Modules\CampaignSms\Services\SmsTemplateHelper;
use Modules\CampaignSms\Services\SmsTokenService;

class ProcessRefillRemindersCommand extends Command
{
    protected $signature = 'campaignsms:process-refills';

    protected $description = 'Send due medicine refill reminder SMS and schedule next run';

    public function handle(
        SmsTokenService $tokenService,
        RefillReminderScheduler $scheduler,
        ModuleUtil $moduleUtil
    ): int {
        if (! $moduleUtil->isModuleInstalled('CampaignSms')) {
            $this->info('CampaignSms module not installed.');

            return self::SUCCESS;
        }

        $now = Carbon::now();
        $reminders = SmsRefillReminder::query()
            ->where('is_active', true)
            ->where('next_run_at', '<=', $now)
            ->with(['contact', 'product'])
            ->orderBy('id')
            ->limit(500)
            ->get();

        foreach ($reminders as $reminder) {
            $business = Business::find($reminder->business_id);
            if (! $business) {
                continue;
            }

            if (! $tokenService->businessHasSmsConfigured($business)) {
                \Log::warning('CampaignSms refill skipped: SMS not configured for business '.$business->id);

                continue;
            }

            $contact = $reminder->contact;
            $product = $reminder->product;
            if (! $contact || ! $product) {
                continue;
            }

            if ((int) $contact->is_default === 1) {
                continue;
            }

            $mobile = trim((string) $contact->mobile);
            if ($mobile === '') {
                continue;
            }

            $lastPurchase = $scheduler->lastPurchaseAt(
                (int) $business->id,
                (int) $contact->id,
                (int) $reminder->product_id
            );

            $interval = (int) $reminder->interval_days;
            $rb = $scheduler->reminderDaysBeforeForBusiness((int) $business->id);

            if ($lastPurchase && $scheduler->isTooEarlyToRemind($now, $lastPurchase, $interval, $rb)) {
                $reminder->next_run_at = $scheduler->computeNextRunFromPurchase($lastPurchase, $interval, $rb);
                $reminder->save();

                continue;
            }

            $settings = SmsCampaignSetting::where('business_id', $business->id)->first();
            $template = $reminder->template_body;
            if ($template === null || $template === '') {
                $template = ($settings && ! empty($settings->default_refill_template))
                    ? $settings->default_refill_template
                    : '';
            }
            if ($template === '') {
                $template = __('campaignsms::lang.default_refill_template_placeholder');
            }

            $customerName = $contact->name;
            $productName = $product->name;
            $businessName = $business->name;

            $body = SmsTemplateHelper::refill($template, $customerName, $productName, $businessName);
            $segments = $tokenService->segmentCount($body);

            if (! $tokenService->canAfford($business->id, $segments)) {
                \Log::warning('CampaignSms refill skipped: insufficient tokens for business '.$business->id);

                continue;
            }

            if (! $tokenService->tryDeduct($business->id, $segments)) {
                continue;
            }

            try {
                $result = $tokenService->sendSms($business, $mobile, $body);
                if ($result === false) {
                    throw new \RuntimeException('SMS send returned false');
                }
                $reminder->last_sent_at = $now;
                $reminder->next_run_at = $scheduler->computeNextRunAfterSend($now, $interval, $rb);
                $reminder->save();
            } catch (\Throwable $e) {
                \Log::emergency('CampaignSms refill send failed: '.$e->getMessage());
                $tokenService->addTokens($business->id, $segments);
            }
        }

        return self::SUCCESS;
    }
}
