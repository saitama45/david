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
        return Inertia::render('Dashboard/Index');
    }

    public function test()
    {
        $data = DB::table('branch')->get();
        return response()->json(['test' => $data]);
    }
}
