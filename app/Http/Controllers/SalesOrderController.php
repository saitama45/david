<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class SalesOrderController extends Controller
{
    public function index()
    {
        return Inertia::render('SalesOrder/Index');
    }
}
