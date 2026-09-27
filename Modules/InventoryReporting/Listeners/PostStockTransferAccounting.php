<?php

namespace Modules\InventoryReporting\Listeners;

use App\Events\StockTransferCreatedOrModified;
use Modules\InventoryReporting\Services\InventoryAccountingService;

class PostStockTransferAccounting
{
    public function handle(StockTransferCreatedOrModified $event): void
    {
        try {
            $svc = app(InventoryAccountingService::class);

            if ($event->action === 'deleted') {
                if ($event->deletedBusinessId && $event->deletedSellTransferId) {
                    $svc->removeStockTransferBySellTransferId(
                        (int) $event->deletedBusinessId,
                        (int) $event->deletedSellTransferId
                    );
                } elseif ($event->stock) {
                    $t = $event->stock;
                    $svc->removeStockTransferBySellTransferId((int) $t->business_id, (int) $t->id);
                }

                return;
            }

            $sell = $event->stock;
            if (! $sell || $sell->type !== 'sell_transfer') {
                return;
            }

            $sell = $sell->fresh();
            if (! $sell || $sell->type !== 'sell_transfer') {
                return;
            }

            $uid = auth()->check() ? (int) auth()->id() : (int) ($sell->created_by ?? 0);
            $svc->postStockTransfer($sell, $uid);
        } catch (\Throwable $e) {
            \Log::warning('InventoryReporting PostStockTransferAccounting: '.$e->getMessage());
        }
    }
}
