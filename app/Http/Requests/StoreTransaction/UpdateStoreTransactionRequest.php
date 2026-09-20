<?php

namespace App\Http\Requests\StoreTransaction;

class UpdateStoreTransactionRequest extends StoreStoreTransactionRequest
{
    public function rules(): array
    {
        return parent::rules() + ['correction_reason' => ['required', 'string', 'min:10', 'max:1000']];
    }
}
