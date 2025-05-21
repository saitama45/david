<?php

namespace App\Http\Controllers;

use App\Imports\WIPIngredientImport;
use App\Imports\WIPListImport;
use App\Models\WIP;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class WIPListController extends Controller
{
    public function index()
    {
        $search = request('search');

        $query = WIP::query()->with('wip_ingredients.product');

        if ($query)
            $query->whereAny(['sap_code', 'name'], 'like', "%{$search}%");

        $wips = $query->latest()->paginate(10);

        return Inertia::render('WIPList/Index', [
            'wips' => $wips,
            'filters' => request()->only(['search'])
        ]);
    }

    public function importWipList(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv'
        ]);

        Excel::import(new WIPListImport, $request->file('file'));

        return back();
    }

    public function importWipIngredients(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv'
        ]);

        Excel::import(new WIPIngredientImport, $request->file('file'));

        return back();
    }
}
