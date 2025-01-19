<?php

namespace App\Http\Controllers;

use App\Models\CostCenter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CostCenterController extends Controller
{
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
}
