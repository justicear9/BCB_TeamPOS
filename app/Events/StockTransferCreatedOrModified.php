<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockTransferCreatedOrModified
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $stock;

    public $action;

    /** @var int|null Used when the sell_transfer row is already deleted (after destroy). */
    public $deletedBusinessId;

    /** @var int|null */
    public $deletedSellTransferId;

    /**
     * @param  mixed  $stock  sell_transfer Transaction, or null when firing after delete by id
     * @return void
     */
    public function __construct($stock, $action, ?int $deletedBusinessId = null, ?int $deletedSellTransferId = null)
    {
        $this->stock = $stock;
        $this->action = $action;
        $this->deletedBusinessId = $deletedBusinessId;
        $this->deletedSellTransferId = $deletedSellTransferId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('channel-name');
    }
}
