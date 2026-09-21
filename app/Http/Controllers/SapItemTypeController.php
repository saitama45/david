<?php

namespace App\Http\Controllers;

use App\Http\Services\SapItemTypeService;
use App\Models\SapItemType;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The managed list of SAP Item Types. There is deliberately no delete: items
 * point at a type, so a type that is no longer wanted is deactivated instead.
 */
class SapItemTypeController extends Controller
{
    public function index()
    {
        $search = request('search');

        $types = SapItemType::query()
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->withCount(['assignments as item_count' => fn ($q) => $q->whereNotNull('sap_item_type_id')])
            ->orderBy('name')
            ->paginate(10)->withQueryString();

        return Inertia::render('SapItemType/Index', [
            'types' => $types,
            'filters' => request()->only(['search']),
        ]);
    }

    public function store(Request $request)
    {
        SapItemType::create($this->validated($request) + ['is_active' => true]);

        return to_route('sap-item-types.index');
    }

    public function update(Request $request, $id)
    {
        $type = SapItemType::findOrFail($id);
        $type->update($this->validated($request, $type->id) + $request->validate(['is_active' => ['required', 'boolean']]));

        return to_route('sap-item-types.index');
    }

    /** Names are stored upper-cased so an import file's " food " matches FOOD. */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $request->merge(['name' => SapItemTypeService::normalize($request->input('name'))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('sap_item_types', 'name')
                ->where('entity_id', app(EntityContext::class)->id())
                ->ignore($ignoreId)],
        ]);
    }
}
