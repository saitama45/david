<?php

namespace App\Http\Controllers;

use App\Http\Services\OrderApprovalService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmergencyOrderApprovalController extends Controller
{
    protected $orderApprovalService;

    public function __construct(OrderApprovalService $orderApprovalService)
    {
        $this->orderApprovalService = $orderApprovalService;
    }

    public function index()
    {
        $data = $this->orderApprovalService->getOrdersAndCounts('manager', null, 'emergency_order');

        return Inertia::render('EmergencyOrderApproval/Index', [
            'orders' =>  $data['orders'],
            'filters' => request()->only(['search', 'currentFilter']),
            'counts' => $data['counts']
        ]);
    }

    public function show($id)
    {
        $order =  $this->orderApprovalService->getOrder($id);
        $orderedItems = $this->orderApprovalService->getOrderItems($order);
        return Inertia::render('EmergencyOrderApproval/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems
        ]);
    }
}
