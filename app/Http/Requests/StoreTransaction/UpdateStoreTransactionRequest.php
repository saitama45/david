<?php

namespace App\Http\Requests\StoreTransaction;

use App\Models\StoreTransaction;

class UpdateStoreTransactionRequest extends StoreStoreTransactionRequest
{
    public function rules(): array
    {
        return parent::rules() + ['correction_reason' => ['required', 'string', 'min:10', 'max:1000']];
    }

    /**
     * A correction keeps the date and store the sale already has; only moving it to another
     * date or store is held to the closed period.
     */
    protected function openPeriodRule(): \Closure
    {
        $sale = $this->route('store_transaction');
        $moved = ! $sale instanceof StoreTransaction
            || (string) $this->input('order_date') !== $sale->order_date?->format('Y-m-d')
            || (int) $this->input('store_branch_id') !== (int) $sale->store_branch_id;

        return $moved ? parent::openPeriodRule() : fn () => null;
    }
}
