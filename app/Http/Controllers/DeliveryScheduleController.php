<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class DeliveryScheduleController extends Controller
{
    public function index()
    {
        return Inertia::render('DeliverySchedule/Index');
    }

    public function edit($id)
    {
        return Inertia::render('DeliverySchedule/Edit');
    }
}
