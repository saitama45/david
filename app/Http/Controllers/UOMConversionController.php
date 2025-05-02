<?php

namespace App\Http\Controllers;

use App\Imports\UOMConversionImport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class UOMConversionController extends Controller
{
    public function index()
    {
        return Inertia::render('UOMConversion/Index');
    }

    public function import(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv'
        ]);

        Excel::import(new UOMConversionImport, $validated['file']);

        return back();
    }
}
