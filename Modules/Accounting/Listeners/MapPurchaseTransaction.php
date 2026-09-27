<?php

namespace Modules\Accounting\Listeners;

use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Events\PurchaseCreatedOrModified;
use App\BusinessLocation;

class MapPurchaseTransaction
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  object  $event
     * @return void
     */
    public function handle(PurchaseCreatedOrModified $event)
    {
        //get location setting and check if default is set or not, if set the proceed.
        $business_location = BusinessLocation::find($event->transaction->location_id);
        $accounting_default_map = json_decode($business_location->accounting_default_map, true);

        $deposit_to = isset($accounting_default_map['purchases']['deposit_to']) ? $accounting_default_map['purchases']['deposit_to'] : null;
        $payment_account = isset($accounting_default_map['purchases']['payment_account']) ? $accounting_default_map['purchases']['payment_account'] : null;

        $business_id = $event->transaction->business_id;
        $accountingUtil = new \Modules\Accounting\Utils\AccountingUtil();

        if (isset($event->isDeleted) && $event->isDeleted) {
            try {
                if (! $accountingUtil->deleteMap($business_id, $event->transaction->id, null)) {
                    \Log::warning('Accounting: deleteMap skipped (period locked)', ['type' => 'purchase', 'transaction_id' => $event->transaction->id]);
                }
                if (! $accountingUtil->deleteInventoryMap((int) $business_id, (int) $event->transaction->id)) {
                    \Log::warning('Accounting: deleteInventoryMap skipped (period locked)', ['type' => 'purchase', 'transaction_id' => $event->transaction->id]);
                }
            } catch (\Throwable $e) {
                \Log::error('Accounting deleteMap failed', ['message' => $e->getMessage()]);
            }
        } else {
            if (! is_null($deposit_to) && ! is_null($payment_account)) {
                $type = 'purchase';
                $id = $event->transaction->id;
                $user_id = request()->session()->get('user.id');
                try {
                    if (! $accountingUtil->saveMap($type, $id, $user_id, $business_id, $deposit_to, $payment_account)) {
                        \Log::warning('Accounting: saveMap skipped (period locked)', ['type' => 'purchase', 'transaction_id' => $id]);
                    }
                    if (! $accountingUtil->saveInventoryMapForPurchase($event->transaction, $user_id)) {
                        \Log::warning('Accounting: saveInventoryMapForPurchase skipped (period locked)', ['transaction_id' => $id]);
                    }
                } catch (\Throwable $e) {
                    \Log::error('Accounting saveMap failed', ['message' => $e->getMessage()]);
                }
            } else {
                try {
                    if (! $accountingUtil->saveInventoryMapForPurchase($event->transaction, request()->session()->get('user.id'))) {
                        \Log::warning('Accounting: saveInventoryMapForPurchase skipped (period locked)', ['transaction_id' => $event->transaction->id]);
                    }
                } catch (\Throwable $e) {
                    \Log::error('Accounting saveInventoryMapForPurchase failed', ['message' => $e->getMessage()]);
                }
            }
        }
    }
}
