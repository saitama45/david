<?php

namespace App\Http\Controllers;

use App\Exports\SAPMasterfileExport;
use App\Http\Services\SapItemTypeService;
use App\Imports\SAPMasterfileImport;
use App\Models\ImportLog;
use App\Models\SAPMasterfile;
use App\Models\SapItemType;
use App\Services\ImportQueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;

class SAPMasterfileController extends Controller
{
    //
    
    public function index()
    {
        $search = request('search');
        $filter = request('filter');

        $query = SAPMasterfile::query()->withItemType()->whereItemType(request('type'));

        if ($filter === 'inactive')
            $query->where('is_active', '=', 0);

        if ($filter === 'is_active')
            $query->where('is_active', '=', 1);

        if ($search)
            $query->whereAny(['ItemCode', 'ItemDescription'], 'like', "%$search%");

        $items = $query->latest()->paginate(10)->withQueryString();

        return Inertia::render('SAPMasterfileItem/Index', [
            'items' => $items,
            'filters' => request()->only(['search', 'filter', 'type']),
            'itemTypes' => SapItemType::orderBy('name')->get(['id', 'name', 'is_active']),
        ])->with('success', true);
    }

    public function create()
    {
        return Inertia::render('SAPMasterfileItem/Create', [
        ]);
    }

    public function export()
    {
        $search = request('search');
        $filter = request('filter');

        return Excel::download(
            new SAPMasterfileExport($search, $filter, request('type')),
            'sapitems-list-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function edit($id, SapItemTypeService $types)
    {
        $item = SAPMasterfile::findOrFail($id);
        $currentTypeId = $types->typeIdFor((int) $item->entity_id, (string) $item->ItemCode);

        return Inertia::render('SAPMasterfileItem/Edit', [
            'item' => $item,
            'currentTypeId' => $currentTypeId,
            'itemTypes' => $types->options($currentTypeId),
            // The type is shared by every UOM row of the code; the form says so.
            'uomCount' => SAPMasterfile::where('ItemCode', $item->ItemCode)->count(),
        ]);
    }

    public function show($id)
    {
        $items = SAPMasterfile::withItemType()->findOrFail($id);
        return Inertia::render('SAPMasterfileItem/Show', [
            'item' => $items,
            'itemTypeName' => $items->sap_item_type_name,
        ]);
    }

    public function store(Request $request)
    {

        $validated = $request->validate([
            'ItemCode' => ['nullable'],
            'ItemDescription' => ['nullable'],
            'AltQty' => ['nullable'],
            'BaseQty' => ['nullable'],
            'AltUOM' => ['nullable'],
            'BaseUOM' => ['required'],
            'is_active' => ['nullable'],
        ]);

        SAPMasterfile::create($validated);
        return to_route("sapitems.index");
    }

    public function destroy($id)
    {
        $items = SAPMasterfile::findOrFail($id);

        $items->delete();
        return to_route('sapitems.index');
    }

    public function update(Request $request, $id, SapItemTypeService $types)
    {
        $item = SAPMasterfile::findOrFail($id);
        $currentTypeId = $types->typeIdFor((int) $item->entity_id, (string) $item->ItemCode);

        $validated = $request->validate([
            'ItemCode' => ['nullable'],
            'ItemDescription' => ['nullable'],
            'AltQty' => ['nullable'],
            'BaseQty' => ['nullable'],
            'AltUOM' => ['nullable'],
            'BaseUOM' => ['required'],
            'is_active' => ['nullable'],
            // A deactivated type stays valid for items already carrying it, so
            // saving an unrelated field does not force a new type.
            'sap_item_type_id' => ['nullable', 'integer', Rule::exists('sap_item_types', 'id')
                ->where('entity_id', $item->entity_id)
                ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $currentTypeId ?? 0))],
        ]);

        DB::transaction(function () use ($item, $validated, $request, $types) {
            $item->update(collect($validated)->except('sap_item_type_id')->all());

            if ($request->has('sap_item_type_id')) {
                $types->assign((int) $item->entity_id, (string) $item->ItemCode, $validated['sap_item_type_id'] ?? null);
            }
        });

        return to_route("sapitems.index");
    }

    public function import(Request $request)
    {
        $request->validate([
            'products_file' => 'required|mimes:xlsx,xls,csv'
        ]);

        $originalName = $request->file('products_file')->getClientOriginalName();
        $path = $request->file('products_file')->store('imports/sap', 'local');

        $log = ImportLog::create([
            'user_id'           => auth()->id(),
            'type'              => 'sap_masterfile',
            'original_filename' => $originalName,
            'source_file_path'  => $path,
            'status'            => 'pending',
        ]);

        app(ImportQueueService::class)->dispatchNextPending();

        return redirect()->route('sapitems.index')
            ->with('info', 'Import queued successfully. Visit the Work Queue page to monitor progress and download the skipped items log when complete.');
    }
}
