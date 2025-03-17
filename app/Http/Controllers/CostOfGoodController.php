<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class CostOfGoodController extends Controller
{
    public function index()
    {
        return Inertia::render('CostOfGood/Index');
    }
}
