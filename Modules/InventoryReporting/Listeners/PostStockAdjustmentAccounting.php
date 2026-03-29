<?php

namespace Modules\InventoryReporting\Listeners;

use App\Events\StockAdjustmentCreatedOrModified;
use Modules\InventoryReporting\Services\InventoryAccountingService;

class PostStockAdjustmentAccounting
{
    public function handle(StockAdjustmentCreatedOrModified $event): void
    {
        try {
            $svc = app(InventoryAccountingService::class);
            $adj = $event->stockAdjustment;

            if ($event->action === 'added' && $adj && $adj->type === 'stock_adjustment') {
                $uid = auth()->check() ? (int) auth()->id() : (int) ($adj->created_by ?? 0);
                $svc->postStockDecrease($adj, $uid);
            }

            if ($event->action === 'deleted' && $adj && $adj->type === 'stock_adjustment') {
                $svc->removeByTransactionId((int) $adj->business_id, (int) $adj->id);
            }
        } catch (\Throwable $e) {
            \Log::warning('InventoryReporting PostStockAdjustmentAccounting: '.$e->getMessage());
        }
    }
}
