<?php

namespace App\Http\Controllers;

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
        $filter = request('filter');

        $query = SAPMasterfile::query();
        
        if ($search)
            $query->whereAny(['ItemNo', 'ItemDescription'], 'like', "%$search%");
        /** @var \Illuminate\Pagination\LengthAwarePaginator $items */
        $items = $query->paginate(10)->withQueryString();

        return Inertia::render('SAPMasterfileItem/Index', [
            'items' => $items,
            'filters' => request()->only(['search', 'filter'])
        ])->with('success', true);
    }

}
