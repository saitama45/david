<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Exports\CSApprovalExport;
use App\Http\Requests\OrderApproval\ApproveOrderRequest;
use App\Http\Services\CSCommitService;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class CSApprovalController extends Controller
{
    protected $csCommitService;

    public function __construct(CSCommitService $csCommitService)
    {
        $this->csCommitService = $csCommitService;
    }
    public function index()
    {
        $data = $this->csCommitService->getOrdersAndCounts('cs');
        return Inertia::render('CSApproval/Index', [
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
            new CSApprovalExport($search, $filter),
            'cs-approvals-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function show($id)
    {
        $order =  $this->csCommitService->getOrder($id);
        $orderedItems = $this->csCommitService->getOrderItems($order);
        return Inertia::render('CSApproval/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems
        ]);
    }

    public function approve(ApproveOrderRequest $request)
    {
        $this->csCommitService->commitOrder($request->validated());
        return to_route('cs-approvals.index');
    }

    // public function reject(Request $request)
    // {
    //     $validated = $request->validate([
    //         'id' => ['required'],
    //         'remarks' => ['sometimes']
    //     ]);
    //     $storeOrder = StoreOrder::findOrFail($validated['id']);
    //     $storeOrder->update([
    //         'order_request_status' => OrderRequestStatus::REJECTED->value,
    //         'commiter_id' => Auth::user()->id,
    //         'commited_action_date' => Carbon::now()
    //     ]);
    //     if (!empty($validated['remarks'])) {
    //         $storeOrder->store_order_remarks()->create([
    //             'user_id' => Auth::user()->id,
    //             'action' => 'cs rejected order',
    //             'remarks' => $validated['remarks']
    //         ]);
    //     }
    //     return to_route('cs-approvals.index');
    // }
}
