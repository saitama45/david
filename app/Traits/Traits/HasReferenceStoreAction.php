<?php

namespace App\Traits\traits;

use Illuminate\Http\Request;

trait HasReferenceStoreAction
{
    public function store(Request $request)
    {
        $rules = 'unique:' . $this->getTableName() . ',name';
        $validated = $request->validate([
            'name' => ['required', $rules],
            'remarks' => ['nullable']
        ]);

        $this->getModel()::create($validated);
        return redirect()->route($this->getRouteName());
    }

    abstract protected function getTableName();

    abstract protected function getModel();

    abstract protected function getRouteName();
}
