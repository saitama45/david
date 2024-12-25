<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class FruitAndVegetableController extends Controller
{
    public function index()
    {
        return Inertia::render('FruitAndVegetableOrder/Index', [

        ]);
    }
}
