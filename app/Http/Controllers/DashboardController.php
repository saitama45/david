<?php

namespace App\Http\Controllers;

use App\Enum\UserRole;
use App\Mail\OneTimePasswordMail;
use App\Models\Branch;
use App\Models\ProductInventory;
use App\Models\StoreOrder;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $user = User::with(['roles', 'store_branches'])->findOrFail(Auth::user()->id);
        $assignedBranches = $user->store_branches->pluck('id')->toArray();
        $userRoles = $user->roles->pluck('name')->toArray();
        $branches = $user->store_branches->pluck('name', 'id')->toArray();
        $branchId = request('branchId') ?? array_keys($branches)[0];

        // Get counts for each status
        $orderCounts = StoreOrder::where('store_branch_id', $branchId)
            ->selectRaw('
            COUNT(CASE WHEN order_request_status = "pending" THEN 1 END) as pending_count,
            COUNT(CASE WHEN order_request_status = "approved" THEN 1 END) as approved_count,
            COUNT(CASE WHEN order_request_status = "rejected" THEN 1 END) as rejected_count
        ')
            ->first();

        return Inertia::render('StoreDashboard/Index', [
            'branches' => $branches,
            'orderCounts' => [
                'pending' => $orderCounts->pending_count ?? 0,
                'approved' => $orderCounts->approved_count ?? 0,
                'rejected' => $orderCounts->rejected_count ?? 0
            ],
            'filters' => request()->only(['branchId']),
        ]);
    }

    public function test()
    {
        try {
            $to = "admin@gmail.com";
            $otp = random_int(000000, 999999);
            $response = Mail::to($to)->send(new OneTimePasswordMail($otp));
            dd($response);
        } catch (Exception $e) {
            dd($e->getMessage());
        }
    }
}
