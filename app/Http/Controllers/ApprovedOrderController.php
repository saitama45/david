<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Models\StoreOrder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ApprovedOrderController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = StoreOrder::query()->with(['store_branch', 'supplier'])->where('order_request_status', OrderRequestStatus::APRROVED->value);

        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%');

        $orders = $query
            ->latest()
            ->paginate(10);

        return Inertia::render('ApprovedOrder/Index', [
            'orders' => $orders
        ]);
    }
}
