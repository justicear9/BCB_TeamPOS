<?php

namespace App\Events;

use App\Transaction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OpeningStockCreatedOrModified
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $transaction;

    public $action;

    /** @var int|null */
    public $deletedBusinessId;

    /** @var int|null */
    public $deletedTransactionId;

    /**
     * @param  string  $action  'saved' or 'deleted'
     */
    public function __construct(string $action, ?Transaction $transaction = null, ?int $deletedBusinessId = null, ?int $deletedTransactionId = null)
    {
        $this->action = $action;
        $this->transaction = $transaction;
        $this->deletedBusinessId = $deletedBusinessId;
        $this->deletedTransactionId = $deletedTransactionId;
    }
}
