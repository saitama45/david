<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use App\Models\StoreOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class PDFReportController extends Controller
{
    public function index()
    {
        return view('pdf.store-orders-report');
    }

    public function storeOrders(Request $request)
    {
        $validated = $request->only('branch', 'start_date', 'end_date');

        $start_date = Carbon::parse($validated['start_date'])->addDay()->format('Y-m-d');
        $end_date = Carbon::parse($validated['end_date'])->addDay()->format('Y-m-d');

        $orders = StoreOrder::with('supplier')
            ->where('store_branch_id', $validated['branch'])
            ->whereBetween('order_date', [$start_date, $end_date])
            ->get();

        $pdf = Pdf::loadView('pdf.store-orders-report', [
            'branch' => StoreBranch::findOrFail($validated['branch'])->name,
            'orders' => $orders,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'date_generated' => Carbon::now()->format('Y-m-d H:i:s'),
            'generated_by' => Auth::user()->full_name,
            'pending' => $orders->where('order_status', 'pending')->count(),
            'approved' => $orders->where('order_status', 'approved')->count(),
            'commited' => $orders->where('order_status', 'commited')->count(),
        ]);

        return $pdf->setPaper('legal', 'landscape')->download('store-orders.pdf');
    }
}
