<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class PDFReportController extends Controller
{
    public function index()
    {
        return view('pdf.store-orders-report');
    }

    public function storeOrders(Request $request)
    {
        dd($request);
        $pdf = Pdf::loadView('pdf.store-orders-report', []);

        return $pdf->setPaper('legal', 'landscape')->download('store-orders.pdf');
    }
}
