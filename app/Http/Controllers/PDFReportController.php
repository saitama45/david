<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PDFReportController extends Controller
{
    public function index()
    {
        return view('pdf.store-orders-report');
    }
}
