<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CashPullOutController extends Controller
{
    public function index()
    {
        return Inertia::render('CashPullOut/Index');
    }

    public function create()
    {
        $products = ProductInventory::options();
        return Inertia::render('CashPullOut/Create', [
            'products' => $products
        ]);
    }
}
