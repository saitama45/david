<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrderReceivingController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = Order::query()->with(['vendor', 'branch']);
        if ($search)
            $query->where('SONumber', 'like', "%$search%");
        $orders = $query->paginate(10);
        return Inertia::render('OrderReceiving/Index', [
            'orders' => $orders,
            'filters' => request()->only(['search'])
        ]);
    }
}
