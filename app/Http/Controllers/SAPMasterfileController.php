<?php

namespace App\Http\Controllers;

use App\Exports\SAPMasterfileExport;
use App\Models\SAPMasterfile;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class SAPMasterfileController extends Controller
{
    //
    public function index()
    {
        $search = request('search');
        $query = SAPMasterfile::query();

        if ($search)
            $query->whereAny(['ItemNo', 'ItemDescription'], 'like', "%$search%");

        $items = $query->latest()->paginate(10)->withQueryString();
        return Inertia::render('SAPMasterfileItem/Index', [
            'items' => $items,
            'filters' => request()->only(['search'])
        ]);
    }

    public function export(Request $request)
    {
        $search = $request->input('search');

        return Excel::download(
            new SAPMasterfileExport($search),
            'sapitems-list-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function show($id)
    {
        $items = SAPMasterfile::findOrFail($id);
        return Inertia::render('SAPMasterfileItem/Show', [
            'items' => $items
        ]);
    }

    public function edit($id)
    {
        $items = SAPMasterfile::findOrFail($id);

        return Inertia::render('SAPMasterfileItem/Edit', [
            'items' => $items
        ]);
    }

    public function create()
    {
        return Inertia::render('SAPMasterfileItem/Create');
    }

    public function destroy($id)
    {
        $items = SAPMasterfile::findOrFail($id);

        $items->delete();
        return to_route('sapitems-list.index');
    }

    public function store(Request $request)
    {

        $validated = $request->validate([
            'ItemNo' => ['required', 'unique:ItemNo'],
            'ItemDescription' => ['required', 'unique:ItemDescription'],
            'AltQty' => ['nullable'],
            'BaseQty' => ['nullable'],
            'AltUOM' => ['nullable'],
            'BaseUOM' => ['required'],
            'is_active' => ['nullable'],
        ]);

        SAPMasterfile::create($validated);
        return to_route("sapitems-list.index");
    }

    public function update(Request $request, $id)
    {
        $item = SAPMasterfile::findOrFail($id);
        $validated = $request->validate([         
            'ItemNo' => ['required', 'unique:ItemNo' . $id],
            'ItemDescription' => ['required', 'unique:ItemDescription' . $id],
            'AltQty' => ['nullable'],
            'BaseQty' => ['nullable'],
            'AltUOM' => ['nullable'],
            'BaseUOM' => ['required'],
            'is_active' => ['nullable'],
        ]);
        $item->update($validated);
        return to_route("sapitems-list.index");
    }
}
