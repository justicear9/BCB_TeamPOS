<?php

namespace Modules\Accounting\Listeners;

use App\Events\SalesReturnCreatedOrModified;
use App\Transaction;
use App\Utils\ModuleUtil;

class MapSalesReturnTransaction
{
    public function __construct(
        protected ModuleUtil $moduleUtil
    ) {}

    public function handle(SalesReturnCreatedOrModified $event): void
    {
        try {
            $businessId = null;
            if ($event->sellReturn) {
                $businessId = (int) $event->sellReturn->business_id;
            } elseif ($event->deletedBusinessId) {
                $businessId = (int) $event->deletedBusinessId;
            }

            if (! $businessId || ! $this->moduleUtil->isModuleInstalled('Accounting')) {
                return;
            }

            if (! $this->moduleUtil->hasThePermissionInSubscription($businessId, 'accounting_module')) {
                return;
            }

            $util = new \Modules\Accounting\Utils\AccountingUtil();
            $uid = auth()->check() ? (int) auth()->id() : null;

            if ($event->action === 'deleted' && $event->deletedSellReturnId) {
                $util->deleteSellReturnMap($businessId, (int) $event->deletedSellReturnId);
                if ($event->deletedParentSellId) {
                    $parent = Transaction::where('business_id', $businessId)
                        ->where('id', (int) $event->deletedParentSellId)
                        ->where('type', 'sell')
                        ->first();
                    if ($parent) {
                        $util->saveInventoryMapForSell($parent, $uid);
                    }
                }

                return;
            }

            $sr = $event->sellReturn;
            if ($event->action === 'saved' && $sr && $sr->type === 'sell_return') {
                $sr = $sr->fresh();
                if (! $sr || $sr->type !== 'sell_return') {
                    return;
                }

                $util->saveSellReturnAccounting($sr, $uid);

                $parentId = (int) $sr->return_parent_id;
                if ($parentId > 0) {
                    $parent = Transaction::where('business_id', $businessId)
                        ->where('id', $parentId)
                        ->where('type', 'sell')
                        ->first();
                    if ($parent) {
                        $util->saveInventoryMapForSell($parent, $uid);
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Accounting MapSalesReturnTransaction: '.$e->getMessage());
        }
    }
}
