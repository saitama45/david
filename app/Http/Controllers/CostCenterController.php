<?php

namespace App\Http\Controllers;

use App\Models\CostCenter;
use App\Traits\traits\HasReferenceStoreAction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CostCenterController extends Controller
{
    use HasReferenceStoreAction;
    public function index()
    {
        $search = request('search');
        $query = CostCenter::query();

        if ($search)
            $query->where('name', 'like', "%$search%");
        $costCenters = $query->latest()->paginate(10)->withQueryString();

        return Inertia::render('CostCenter/Index', [
            'costCenters' => $costCenters,
            'filters' => request()->only(['search'])
        ]);
    }

    public function destroy($id)
    {
        $category = CostCenter::with('stock_managements')->findOrFail($id);

        if ($category->stock_managements->count() > 0) {
            return back()->withErrors([
                'message' => "Can't delete this cost center because there are data associated with it."
            ]);
        }

        $category->delete();
        return to_route('cost-centers.index');
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => ['required', 'unique:cost_centers,name,' . $id],
        ]);

        $costCenter = CostCenter::findOrFail($id);
        $costCenter->update($validated);

        return to_route('cost-centers.index');
    }

    protected function getTableName()
    {
        return 'cost_centers';
    }

    protected function getModel()
    {
        return CostCenter::class;
    }

    protected function getRouteName()
    {
        return "cost-centers.index";
    }
}
