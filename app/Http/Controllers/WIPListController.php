<?php

namespace App\Http\Controllers;

use App\Models\WIP;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
}
