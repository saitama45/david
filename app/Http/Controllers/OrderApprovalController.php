<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Exports\OrderApprovalsExport;
use App\Http\Requests\OrderApproval\ApproveOrderRequest;
use App\Http\Requests\OrderApproval\RejectOrderRequest;
use App\Http\Services\OrderApprovalService;
use App\Models\Order;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;


class OrderApprovalController extends Controller
{
    protected $orderApprovalService;

    public function __construct(OrderApprovalService $orderApprovalService)
    {
        $this->orderApprovalService = $orderApprovalService;
    }

    public function index()
    {
        $data = $this->orderApprovalService->getOrdersAndCounts();

        return Inertia::render('OrderApproval/Index', [
            'orders' =>  $data['orders'],
            'filters' => request()->only(['search', 'currentFilter']),
            'counts' => $data['counts']
        ]);
    }

    public function export()
    {
        $search = request('search');
        $filter = request('currentFilter') ?? 'pending';

        return Excel::download(
            new OrderApprovalsExport($search, $filter),
            'orders-approval-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function show($id)
    {
        $order =  $this->orderApprovalService->getOrder($id);
        $orderedItems = $this->orderApprovalService->getOrderItems($order);
        return Inertia::render('OrderApproval/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems
        ]);
    }

    public function approve(ApproveOrderRequest $request)
    {
        $this->orderApprovalService->approveOrder($request->validated());

        return to_route('orders-approval.index');
    }

    public function reject(RejectOrderRequest $request)
    {
        $this->orderApprovalService->rejectOrder($request->validated());
        return to_route('orders-approval.index');
    }
}
