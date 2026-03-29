<?php

namespace Modules\CampaignSms\Http\Controllers;

use App\Utils\ModuleUtil;
use Carbon\Carbon;
use Illuminate\Routing\Controller;
use Menu;
use Modules\CampaignSms\Entities\SmsRefillReminder;
use Modules\CampaignSms\Services\PosInvoiceRefillSnapshotService;
use Modules\CampaignSms\Services\RefillReminderScheduler;

class DataController extends Controller
{
    public function user_permissions()
    {
        return [
            [
                'value' => 'campaignsms.send_bulk',
                'label' => __('campaignsms::lang.permission_send_bulk'),
                'default' => false,
            ],
            [
                'value' => 'campaignsms.manage_refills',
                'label' => __('campaignsms::lang.permission_manage_refills'),
                'default' => false,
            ],
            [
                'value' => 'campaignsms.view_logs',
                'label' => __('campaignsms::lang.permission_view_logs'),
                'default' => false,
            ],
        ];
    }

    public function modifyAdminMenu()
    {
        $module_util = new ModuleUtil();
        if (! $module_util->isModuleInstalled('CampaignSms')) {
            return;
        }

        if (auth()->user()->can('campaignsms.send_bulk')
            || auth()->user()->can('campaignsms.manage_refills')
            || auth()->user()->can('campaignsms.view_logs')) {
            Menu::modify('admin-sidebar-menu', function ($menu) {
                $menu->dropdown(
                    __('campaignsms::lang.sms_campaigns'),
                    function ($sub) {
                        if (auth()->user()->can('campaignsms.send_bulk')) {
                            $sub->url(
                                action([\Modules\CampaignSms\Http\Controllers\SmsCampaignController::class, 'create']),
                                __('campaignsms::lang.bulk_sms'),
                                ['icon' => '', 'active' => request()->routeIs('campaignsms.campaigns.create')]
                            );
                        }
                        if (auth()->user()->can('campaignsms.view_logs')) {
                            $sub->url(
                                action([\Modules\CampaignSms\Http\Controllers\SmsCampaignController::class, 'index']),
                                __('campaignsms::lang.campaign_history'),
                                ['icon' => '', 'active' => request()->routeIs('campaignsms.campaigns.index')]
                            );
                        }
                        if (auth()->user()->can('campaignsms.manage_refills')) {
                            $sub->url(
                                action([\Modules\CampaignSms\Http\Controllers\RefillSettingsController::class, 'edit']),
                                __('campaignsms::lang.refill_templates'),
                                ['icon' => '', 'active' => request()->routeIs('campaignsms.refill-settings.edit')]
                            );
                        }
                    },
                    ['icon' => 'fas fa-sms', 'active' => request()->is('sms-campaigns*')]
                );
            });
        }
    }

    public function get_contact_view_tabs($args = null)
    {
        $module_util = new ModuleUtil();
        if (! $module_util->isModuleInstalled('CampaignSms')) {
            return [];
        }

        if (empty($args['contact'])) {
            return [];
        }

        $contact = $args['contact'];
        if (! in_array($contact->type, ['customer', 'both'], true)) {
            return [];
        }

        if (! auth()->user()->can('campaignsms.manage_refills')) {
            return [];
        }

        return [
            [
                'tab_menu_path' => 'campaignsms::contact.tab_menu',
                'tab_content_path' => 'campaignsms::contact.tab_content',
                'tab_data' => [
                    'contact' => $contact,
                ],
            ],
        ];
    }

    /**
     * Core POS views (sale_pos.create / sale_pos.edit) include scripts from this hook — no edits to base templates required.
     *
     * @param  array<string, mixed>|null  $args
     * @return array<string, mixed>
     */
    public function get_pos_screen_view($args = null)
    {
        $module_util = new ModuleUtil();
        if (! $module_util->isModuleInstalled('CampaignSms')) {
            return [];
        }

        if (! auth()->user()->can('sell.create') && ! auth()->user()->can('campaignsms.manage_refills')) {
            return [];
        }

        return [
            'module_js_path' => 'campaignsms::pos.refill_pos_script',
            'view_data' => is_array($args) ? $args : [],
        ];
    }

    /**
     * After a POS/direct sale is saved: reschedule refill reminders from purchase date, or create from POS checkbox.
     *
     * @param  array{transaction?: \App\Transaction, input?: array}  $data
     */
    public function after_sale_saved($data)
    {
        try {
            $this->runAfterSaleSaved($data);
        } catch (\Throwable $e) {
            \Log::warning('CampaignSms after_sale_saved: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
        }

        return null;
    }

    /**
     * @param  array{transaction?: \App\Transaction, input?: array}  $data
     */
    protected function runAfterSaleSaved($data): void
    {
        $module_util = new ModuleUtil();
        if (! $module_util->isModuleInstalled('CampaignSms')) {
            return;
        }

        $transaction = $data['transaction'] ?? null;
        $input = $data['input'] ?? [];
        if (! $transaction || $transaction->status !== 'final' || (int) $transaction->is_suspend === 1) {
            return;
        }

        if (! auth()->user()->can('sell.create') && ! auth()->user()->can('campaignsms.manage_refills')) {
            return;
        }

        $transaction->load('contact');
        if (! $transaction->contact || (int) $transaction->contact->is_default === 1) {
            return;
        }

        /** @var RefillReminderScheduler $scheduler */
        $scheduler = app(RefillReminderScheduler::class);
        $saleAt = Carbon::parse($transaction->transaction_date);
        $snapshotRows = [];

        foreach ($input['products'] ?? [] as $line) {
            if (empty($line['product_id'])) {
                continue;
            }

            $productId = (int) $line['product_id'];
            $contactId = (int) $transaction->contact_id;

            $reminder = SmsRefillReminder::where('business_id', $transaction->business_id)
                ->where('contact_id', $contactId)
                ->where('product_id', $productId)
                ->first();

            if ($reminder) {
                $scheduler->rescheduleAfterPurchase($reminder, $saleAt);
            }

            $add = ! empty($line['campaignsms_add_refill']) && (int) $line['campaignsms_add_refill'] === 1;
            if ($add && ! $reminder) {
                $interval = isset($line['campaignsms_interval_days']) ? (int) $line['campaignsms_interval_days'] : 30;
                $scheduler->createFromPosIfMissing(
                    (int) $transaction->business_id,
                    $contactId,
                    $productId,
                    $saleAt,
                    $interval
                );
            }

            if ($add) {
                $snapshotRows[] = [
                    'product_id' => $productId,
                    'interval_days' => isset($line['campaignsms_interval_days']) ? (int) $line['campaignsms_interval_days'] : 30,
                ];
            }
        }

        if ($snapshotRows !== []) {
            /** @var PosInvoiceRefillSnapshotService $snapSvc */
            $snapSvc = app(PosInvoiceRefillSnapshotService::class);
            $payload = $snapSvc->buildLinesFromSale((int) $transaction->business_id, $saleAt, $snapshotRows);
            $snapSvc->saveForTransaction($transaction, $payload);
        }
    }
}
