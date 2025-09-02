<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus; // Added missing use statement for OrderStatus
use App\Exports\OrderApprovalsExport;
use App\Http\Requests\OrderApproval\ApproveOrderRequest;
use App\Http\Requests\OrderApproval\RejectOrderRequest;
use App\Http\Services\OrderApprovalService;
use App\Models\Order; // This model might not be used, consider removing if so
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
        // Pass the currentFilter from the request to the service
        $data = $this->orderApprovalService->getOrdersAndCounts(
            'manager', // page
            null,       // condition (not used for this context)
            null,       // variant (not used for this context)
            request('currentFilter') ?? 'pending' // Pass the current filter from UI
        );

        return Inertia::render('OrderApproval/Index', [
            'orders' =>  $data['orders'],
            'filters' => request()->only(['search', 'currentFilter']),
            'counts' => $data['counts']
        ]);
    }

    public function export()
    {
        $search = request('search');
        // FIX: The frontend passes the filter as 'filter', not 'currentFilter'.
        $filter = request('filter') ?? 'pending';

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
