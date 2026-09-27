<?php

namespace Modules\InventoryReporting\Listeners;

use App\Events\OpeningStockCreatedOrModified;
use Modules\InventoryReporting\Services\InventoryAccountingService;

class PostOpeningStockAccounting
{
    public function handle(OpeningStockCreatedOrModified $event): void
    {
        try {
            $svc = app(InventoryAccountingService::class);

            if ($event->action === 'deleted' && $event->deletedBusinessId && $event->deletedTransactionId) {
                $svc->removeByTransactionId((int) $event->deletedBusinessId, (int) $event->deletedTransactionId);

                return;
            }

            $t = $event->transaction;
            if ($event->action === 'saved' && $t && $t->type === 'opening_stock' && $t->status === 'received') {
                $uid = auth()->check() ? (int) auth()->id() : (int) ($t->created_by ?? 0);
                $svc->postStockIncrease($t, $uid);
            }
        } catch (\Throwable $e) {
            \Log::warning('InventoryReporting PostOpeningStockAccounting: '.$e->getMessage());
        }
    }
}
