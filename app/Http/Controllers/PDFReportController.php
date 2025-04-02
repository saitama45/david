<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use App\Models\StoreOrder;
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
        $validated = $request->validate([
            'branch' => ['nullable'],
            'start_date' => ['nullable'],
            'end_date' => ['nullable']
        ]);

        $orders = StoreOrder::with('supplier')->where('store_branch_id', 4)->get();

        $pdf = Pdf::loadView('pdf.store-orders-report', [
            'branch' => StoreBranch::findOrFail(4)->name,
            'orders' => $orders
        ]);

        return $pdf->setPaper('legal', 'landscape')->download('store-orders.pdf');
    }
}
