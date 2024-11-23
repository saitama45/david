<?php

namespace App\Http\Controllers;

use App\Enum\UserRole;
use App\Models\Branch;
use App\Models\ProductInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $product = ProductInventory::with('unit_of_measurement')->where('inventory_code', '105A2A')->first();
        dd($product);
        return Inertia::render('Dashboard/Index');
    }

    public function test()
    {
        $data = DB::table('branch')->get();
        return response()->json(['test' => $data]);
    }
}
