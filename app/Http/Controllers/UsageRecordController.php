<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class UsageRecordController extends Controller
{
    public function index()
    {
        return Inertia::render('UsageRecord/Index');
    }

    public function create()
    {
        return Inertia::render('UsageRecord/Create');
    }
}
