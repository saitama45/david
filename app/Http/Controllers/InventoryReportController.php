<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class InventoryReportController extends Controller
{
    public function index()
    {
        return Inertia::render('InventoryReport/Index');
    }
}
