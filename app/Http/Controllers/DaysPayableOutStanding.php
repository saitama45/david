<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class DaysPayableOutStanding extends Controller
{
    public function index()
    {
        return Inertia::render('DaysPayableOutstanding/Index');
    }
}
