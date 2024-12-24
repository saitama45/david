<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class SalmonOrderController extends Controller
{
    public function index()
    {
        return Inertia::render('SalmonOrder/Index');
    }
}
