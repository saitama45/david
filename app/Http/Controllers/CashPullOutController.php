<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class CashPullOutController extends Controller
{
    public function index()
    {
        return Inertia::render('CashPullOut/Index');
    }
}
