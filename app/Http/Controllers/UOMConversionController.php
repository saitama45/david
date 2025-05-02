<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class UOMConversionController extends Controller
{
    public function index()
    {
        return Inertia::render('UOMConversion/Index');
    }
}
