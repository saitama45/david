<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class DTSController extends Controller
{
    public function index()
    {
        return Inertia::render('DTSOrder/Index');
    }

    public function create($variant)
    {
        return Inertia::render('DTSOrder/Create');
    }
}
