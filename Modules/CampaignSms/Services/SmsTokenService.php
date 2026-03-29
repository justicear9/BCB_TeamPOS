<?php

namespace Modules\CampaignSms\Services;

use App\Business;
use Illuminate\Support\Facades\DB;
use Modules\CampaignSms\Entities\SmsTokenBalance;

class SmsTokenService
{
    public const SEGMENT_LENGTH = 159;

    public function segmentCount(string $body): int
    {
        $len = mb_strlen($body, 'UTF-8');

        return max(1, (int) ceil($len / self::SEGMENT_LENGTH));
    }

    public function totalTokensForBroadcast(string $body, int $recipientCount): int
    {
        if ($recipientCount <= 0) {
            return 0;
        }

        return $this->segmentCount($body) * $recipientCount;
    }

    public function getOrCreateBalance(int $businessId): SmsTokenBalance
    {
        $row = SmsTokenBalance::firstOrCreate(
            ['business_id' => $businessId],
            ['balance' => 0]
        );

        return $row;
    }

    public function getBalance(int $businessId): int
    {
        return (int) $this->getOrCreateBalance($businessId)->balance;
    }

    public function canAfford(int $businessId, int $tokens): bool
    {
        if ($tokens <= 0) {
            return true;
        }

        return $this->getBalance($businessId) >= $tokens;
    }

    public function tryDeduct(int $businessId, int $amount): bool
    {
        if ($amount <= 0) {
            return true;
        }

        return DB::transaction(function () use ($businessId, $amount) {
            $row = SmsTokenBalance::where('business_id', $businessId)->lockForUpdate()->first();
            if (! $row) {
                $row = new SmsTokenBalance(['business_id' => $businessId, 'balance' => 0]);
                $row->save();
            }
            if ($row->balance < $amount) {
                return false;
            }
            $row->balance = $row->balance - $amount;
            $row->save();

            return true;
        });
    }

    public function addTokens(int $businessId, int $amount): void
    {
        if ($amount === 0) {
            return;
        }
        DB::transaction(function () use ($businessId, $amount) {
            $row = SmsTokenBalance::where('business_id', $businessId)->lockForUpdate()->first();
            if (! $row) {
                $row = new SmsTokenBalance(['business_id' => $businessId, 'balance' => 0]);
            }
            $row->balance = max(0, (int) $row->balance + $amount);
            $row->save();
        });
    }

    public function setBalance(int $businessId, int $balance): void
    {
        DB::transaction(function () use ($businessId, $balance) {
            $row = SmsTokenBalance::where('business_id', $businessId)->lockForUpdate()->first();
            if (! $row) {
                $row = new SmsTokenBalance(['business_id' => $businessId]);
            }
            $row->balance = max(0, $balance);
            $row->save();
        });
    }

    public function businessHasSmsConfigured(Business $business): bool
    {
        $settings = $business->sms_settings ?? [];
        $service = $settings['sms_service'] ?? 'other';

        if ($service === 'nexmo') {
            return ! empty($settings['nexmo_key']) && ! empty($settings['nexmo_secret']);
        }
        if ($service === 'twilio') {
            return ! empty($settings['twilio_sid']) && ! empty($settings['twilio_token']);
        }

        return ! empty($settings['url']) && ! empty($settings['send_to_param_name']) && ! empty($settings['msg_param_name']);
    }

    /**
     * @return mixed Guzzle response, or false when SMS cannot be sent
     */
    public function sendSms(Business $business, string $mobile, string $body)
    {
        /** @var \App\Utils\Util $util */
        $util = app(\App\Utils\Util::class);

        return $util->sendSms([
            'sms_settings' => $business->sms_settings ?? [],
            'mobile_number' => $mobile,
            'sms_body' => $body,
        ]);
    }
}
