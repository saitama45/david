<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OrderApprovalController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = Order::query()->with(['branch', 'vendor']);
        if ($search)
            $query->where('SONumber', 'like', "%$search%");
        $orders = $query->latest()
            ->paginate(10);

        return Inertia::render('OrderApproval/Index', [
            'orders' => $orders,
            'filters' => request()->only(['search'])
        ]);
    }

    public function show($id)
    {
        $orders = DB::select("CALL SP_GET_RECEIVINGITEMS_HEADERID(?,?)", [$id, 1]);
        $orderDetails = DB::select("CALL SP_GET_SO_TRANSACTIONHEADER(?)", [$id]);
        return Inertia::render('OrderApproval/Show', [
            'orders' => $orders,
            'orderDetails' => $orderDetails
        ]);
    }
}
