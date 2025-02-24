<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class StoreTransactionApprovalController extends Controller
{
    public function index()
    {
        return Inertia::render('StoreTransactionApproval/Index');
    }
}
