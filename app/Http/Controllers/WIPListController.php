<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class WIPListController extends Controller
{
    public function index()
    {
        return Inertia::render('WIPList/Index');
    }
}
