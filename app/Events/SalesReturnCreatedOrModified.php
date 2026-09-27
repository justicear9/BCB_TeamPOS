<?php

namespace App\Events;

use App\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Queue\SerializesModels;

class SalesReturnCreatedOrModified
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $sellReturn;

    public $action;

    /** @var int|null */
    public $deletedBusinessId;

    /** @var int|null */
    public $deletedSellReturnId;

    /** @var int|null Parent sale id (for GL refresh after delete) */
    public $deletedParentSellId;

    /**
     * @param  string  $action  saved | deleted
     */
    public function __construct(
        string $action,
        ?Transaction $sellReturn = null,
        ?int $deletedBusinessId = null,
        ?int $deletedSellReturnId = null,
        ?int $deletedParentSellId = null
    ) {
        $this->action = $action;
        $this->sellReturn = $sellReturn;
        $this->deletedBusinessId = $deletedBusinessId;
        $this->deletedSellReturnId = $deletedSellReturnId;
        $this->deletedParentSellId = $deletedParentSellId;
    }
}
