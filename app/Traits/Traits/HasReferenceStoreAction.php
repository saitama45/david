<?php

namespace App\Traits\traits;

use Illuminate\Http\Request;

trait HasReferenceStoreAction
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required'],
            'remarks' => ['nullable']
        ]);

        $this->getModel()::create($validated);
        return redirect()->route($this->getRouteName());
    }

    abstract protected function getModel();

    abstract protected function getRouteName();
}
