<?php

namespace Modules\CampaignSms\Services;

use App\Business;
use App\Contact;

class SmsTemplateHelper
{
    public static function refill(string $template, string $customerName, string $productName, string $businessName): string
    {
        return str_replace(
            ['{customer_name}', '{product_name}', '{business_name}'],
            [$customerName, $productName, $businessName],
            $template
        );
    }

    /**
     * Replace placeholders for bulk SMS. {product_name} has no value in bulk context and becomes empty.
     */
    public static function bulkCampaign(string $template, Contact $contact, Business $business): string
    {
        return str_replace(
            ['{customer_name}', '{product_name}', '{business_name}', '{mobile}'],
            [$contact->name ?? '', '', $business->name ?? '', (string) ($contact->mobile ?? '')],
            $template
        );
    }
}
