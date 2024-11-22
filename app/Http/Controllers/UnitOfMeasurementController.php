<?php

namespace App\Http\Controllers;

use App\Models\UnitOfMeasurement;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UnitOfMeasurementController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = UnitOfMeasurement::query();

        if ($search)
            $query->where('name', 'like', "%$search%");

        $items = $query->paginate(10);

        return Inertia::render('UnitOfMeasurement/Index', [
            'items' => $items,
            'filters' => request()->only(['search'])
        ]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required',
            'remarks' => 'required',
        ]);

        $category = UnitOfMeasurement::findOrFail($id);
        $category->update($validated);


        return to_route('categories.index');
    }
}
