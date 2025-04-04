<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use App\Models\StoreOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PDFReportController extends Controller
{
    public function index()
    {
        return view('pdf.store-orders-report');
    }

    public function storeOrders(Request $request)
    {
        $validated = $request->only('branch', 'start_date', 'end_date');


        $orders = StoreOrder::with('supplier')
            ->where('store_branch_id', $validated['branch'])
            ->whereBetween('order_date', [Carbon::parse($validated['start_date'])->format('Y-m-d'), Carbon::parse($validated['end_date'])->format('Y-m-d')])
            ->get();

        $pdf = Pdf::loadView('pdf.store-orders-report', [
            'branch' => StoreBranch::findOrFail(4)->name,
            'orders' => $orders
        ]);

        return $pdf->setPaper('legal', 'landscape')->download('store-orders.pdf');
    }
}
