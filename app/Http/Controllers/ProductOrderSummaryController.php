<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use App\Models\StoreBranch;
use App\Models\StoreOrderItem;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ProductOrderSummaryController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        if (in_array('so encoder', $user->roles->pluck('name')->toArray()) && !in_array('admin', $user->roles->pluck('name')->toArray())) {
            $assignedBranches = $user->store_branches->pluck('id');
            $branches = StoreBranch::whereIn('id', $assignedBranches)->options();
        } else {
            $branches = StoreBranch::options();
        }
        $search = request('search');
        $dateRange = request('dateRange');
        $supplierId = request('supplierId');
        $branchId = request('branchId');
        $suppliers = Supplier::options();
        $startDate = $dateRange ? Carbon::parse($dateRange[0])->addDay()->format('Y-m-d') : Carbon::yesterday()->format('Y-m-d');
        $endDate = $dateRange ? Carbon::parse($dateRange[1])->addDay()->format('Y-m-d') : $startDate;

        $query = ProductInventory::query()
            ->with(['store_order_items', 'store_order_items.store_order', 'unit_of_measurement']);

        if ($search) {
            $query->whereAny(['name', 'inventory_code'], 'like', "%$search%");
        }

        $query->withSum(['store_order_items' => function ($query) use ($startDate, $endDate, $supplierId, $branchId) {
            $query->whereHas('store_order', function ($subQuery) use ($startDate, $endDate, $supplierId, $branchId) {
                $subQuery->whereBetween('order_date', [$startDate, $endDate]);
                if ($supplierId) {
                    $subQuery->where('supplier_id', $supplierId);
                }
                $user = Auth::user();
                if (in_array('so encoder', $user->roles->pluck('name')->toArray()) && !in_array('admin', $user->roles->pluck('name')->toArray())) {
                    $assignedBranches = $user->store_branches->pluck('id');
                    $subQuery->whereIn('store_branch_id', $assignedBranches);
                }

                if ($branchId) {
                    $subQuery->where('store_branch_id', $branchId);
                }
            });
        }], 'quantity_ordered')
            ->withSum(['store_order_items' => function ($query) use ($startDate, $endDate, $supplierId, $branchId) {
                $query->whereHas('store_order', function ($subQuery) use ($startDate, $endDate, $supplierId, $branchId) {
                    $subQuery->whereBetween('order_date', [$startDate, $endDate]);
                    if ($supplierId) {
                        $subQuery->where('supplier_id', $supplierId);
                    }
                    $user = Auth::user();
                    if (in_array('so encoder', $user->roles->pluck('name')->toArray()) && !in_array('admin', $user->roles->pluck('name')->toArray())) {
                        $assignedBranches = $user->store_branches->pluck('id');
                        $subQuery->whereIn('store_branch_id', $assignedBranches);
                    }

                    if ($branchId) {
                        $subQuery->where('store_branch_id', $branchId);
                    }
                });
            }], 'quantity_received')
            ->whereHas('store_order_items.store_order', function ($query) use ($startDate, $endDate, $supplierId, $branchId) {
                $query->whereBetween('order_date', [$startDate, $endDate]);
                if ($supplierId) {
                    $query->where('supplier_id', $supplierId);
                }
                $user = Auth::user();
                if (in_array('so encoder', $user->roles->pluck('name')->toArray()) && !in_array('admin', $user->roles->pluck('name')->toArray())) {
                    $assignedBranches = $user->store_branches->pluck('id');
                    $query->whereIn('store_branch_id', $assignedBranches);
                }

                if ($branchId) {
                    $query->where('store_branch_id', $branchId);
                }
            });

        $items = $query->paginate(10)->withQueryString();

        return Inertia::render('ProductOrderSummary/Index', [
            'items' => $items,
            'suppliers' => $suppliers,
            'branches' => $branches,
            'filters' => request()->only(['search', 'dateRange', 'supplierId', 'branchId'])
        ]);
    }

    public function show($id)
    {
        $dateRange = request('dateRange');
        $supplierId = request('supplierId');
        $branchId = request('branchId');
        $startDate = $dateRange ? Carbon::parse($dateRange[0])->addDay()->format('Y-m-d') : Carbon::yesterday()->format('Y-m-d');
        $endDate = $dateRange ? Carbon::parse($dateRange[1])->addDay()->format('Y-m-d') : $startDate;

        $item = ProductInventory::with(['unit_of_measurement'])
            ->with(['store_order_items' => function ($query) use ($startDate, $endDate, $supplierId, $branchId) {
                $query->whereHas('store_order', function ($subQuery) use ($startDate, $endDate, $supplierId, $branchId) {
                    $subQuery->whereBetween('order_date', [$startDate, $endDate]);
                    if ($supplierId) {
                        $subQuery->where('supplier_id', $supplierId);
                    }
                    if ($branchId) {
                        $subQuery->where('store_branch_id', $branchId);
                    }
                })->with(['store_order.store_branch', 'store_order.supplier']);
            }])
            ->findOrFail($id);

        $orders = $item->store_order_items;

        return Inertia::render('ProductOrderSummary/Show', [
            'item' => $item,
            'orders' => $orders,
            'filters' => request()->only(['dateRange'])
        ]);
    }
}
