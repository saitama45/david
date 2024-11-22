<?php

namespace App\Http\Controllers;

use App\Models\Supplier;

class SupplierController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = Supplier::query();

        if ($search)
            $query->where('name', 'like', "%$search%");

        $suppliers = $query->paginate(10);
        return Supplier::render('Supplier/Index', [
            'data' => $suppliers,
            'filters' => request()->only(['search'])
        ]);
    }
}
