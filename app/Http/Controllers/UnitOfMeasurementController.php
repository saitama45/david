<?php

namespace App\Http\Controllers;

use App\Exports\UnitOfMeasurementsExport;
use App\Models\UnitOfMeasurement;
use App\Traits\traits\HasReferenceStoreAction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class UnitOfMeasurementController extends Controller
{
    use HasReferenceStoreAction;
    public function index()
    {
        $search = request('search');
        $query = UnitOfMeasurement::query();

        if ($search)
            $query->where('name', 'like', "%$search%");

        $items = $query->latest()->paginate(10)->withQueryString();

        return Inertia::render('UnitOfMeasurement/Index', [
            'items' => $items,
            'filters' => request()->only(['search'])
        ]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => ['required', 'unique:unit_of_measurements,name,' . $id],
            'remarks' => 'nullable',
        ]);

        $category = UnitOfMeasurement::findOrFail($id);
        $category->update($validated);


        return to_route('unit-of-measurements.index');
    }

    protected function getTableName()
    {
        return 'unit_of_measurements';
    }

    protected function getModel()
    {
        return UnitOfMeasurement::class;
    }

    protected function getRouteName()
    {
        return "unit-of-measurements.index";
    }

    public function export(Request $request)
    {
        $search = $request->input('search');

        return Excel::download(
            new UnitOfMeasurementsExport($search),
            'unit-of-measurements-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function destroy($id)
    {
        $category = UnitOfMeasurement::with('product_inventories')->findOrFail($id);

        if ($category->product_inventories->count() > 0) {
            return back()->withErrors([
                'message' => "Can't delete this uom because there are products associated with it."
            ]);
        }

        $category->delete();
        return to_route('unit-of-measurements.index');
    }
}
