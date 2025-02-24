<?php

namespace App\Observers;

use App\Models\StoreTransactionItem;

class StoreTransactionItemObserver
{
    /**
     * Handle the StoreTransactionItem "created" event.
     */
    public function created(StoreTransactionItem $storeTransactionItem): void
    {
        //
    }

    /**
     * Handle the StoreTransactionItem "updated" event.
     */
    public function updated(StoreTransactionItem $storeTransactionItem): void
    {
        //
    }

    /**
     * Handle the StoreTransactionItem "deleted" event.
     */
    public function deleted(StoreTransactionItem $storeTransactionItem): void
    {
        //
    }

    /**
     * Handle the StoreTransactionItem "restored" event.
     */
    public function restored(StoreTransactionItem $storeTransactionItem): void
    {
        //
    }

    /**
     * Handle the StoreTransactionItem "force deleted" event.
     */
    public function forceDeleted(StoreTransactionItem $storeTransactionItem): void
    {
        //
    }
}
