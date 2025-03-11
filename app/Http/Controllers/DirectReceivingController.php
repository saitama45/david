<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class DirectReceivingController extends Controller
{
    public function index()
    {
        return Inertia::render('DirectReceiving/Index');
    }
}
