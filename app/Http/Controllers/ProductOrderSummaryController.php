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
use Spatie\Browsershot\Browsershot;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Facades\Excel;

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
                    $subQuery->whereIn('store_branch_id', $branchId);
                }
            });
        }], 'quantity_approved')
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
                        $subQuery->whereIn('store_branch_id', $branchId);
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
                    $query->whereIn('store_branch_id', $branchId);
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

    public function downloadOrdersPdf()
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(1000);
        $search = request('search');
        $dateRange = request('dateRange');
        $supplierId = request('supplierId');
        $branchId = request('branchId');
        $startDate = $dateRange ? Carbon::parse($dateRange[0])->addDay()->format('Y-m-d') : Carbon::today()->format('Y-m-d');
        $endDate = $dateRange ? Carbon::parse($dateRange[1])->addDay()->format('Y-m-d') : $startDate;


        $branches = $branchId ? StoreBranch::whereIn('id', $branchId)->get() : StoreBranch::all();

        $query = ProductInventory::query()->with(['store_order_items' => function ($query) use ($startDate, $endDate, $supplierId, $branchId) {
            $query->whereHas('store_order', function ($subQuery) use ($startDate, $endDate, $supplierId, $branchId) {
                $subQuery->whereBetween('order_date', [$startDate, $endDate]);
                if ($supplierId) $subQuery->where('supplier_id', $supplierId);
                if ($branchId) $subQuery->whereIn('store_branch_id', $branchId);
            })->with(['store_order.store_branch']);
        }])
            ->whereHas('store_order_items', function ($query) use ($startDate, $endDate) {
                $query->whereHas('store_order', function ($subQuery) use ($startDate, $endDate) {
                    $subQuery->whereBetween('order_date', [$startDate, $endDate]);
                });
            });

        if ($search) {
            $query->whereAny(['name', 'inventory_code'], 'like', "%$search%");
        }

        $products = $query->get();

        $formattedData = $products->map(function ($product) {
            $branchQuantities = $product->store_order_items
                ->groupBy(fn($item) => $item->store_order->store_branch->id)
                ->mapWithKeys(fn($items, $branchId) => [$branchId => $items->sum('quantity_approved')]);

            return [
                'name' => $product->name,
                'inventory_code' => $product->inventory_code,
                'branch_quantities' => $branchQuantities,
                'total' => $branchQuantities->sum()
            ];
        })->filter(fn($product) => $product['total'] > 0)->values();

        return Excel::download(new class($formattedData, $branches, $startDate, $endDate) implements FromView {
            private $products;
            private $branches;
            private $startDate;
            private $endDate;

            public function __construct($products, $branches, $startDate, $endDate)
            {
                $this->products = $products;
                $this->branches = $branches;
                $this->startDate = $startDate;
                $this->endDate = $endDate;
            }

            public function view(): View
            {
                return view('item-orders-summary-excel', [
                    'products' => $this->products,
                    'branches' => $this->branches,
                    'startDate' => $this->startDate,
                    'endDate' => $this->endDate
                ]);
            }
        }, 'orders-summary.xlsx');
    }

    // public function downloadOrdersPdf()
    // {
    //     ini_set('memory_limit', '1024M');
    //     set_time_limit(1000);

    //     $dateRange = request('dateRange');
    //     $supplierId = request('supplierId');
    //     $branchId = request('branchId');
    //     $startDate = $dateRange ? Carbon::parse($dateRange[0])->addDay()->format('Y-m-d') : Carbon::yesterday()->format('Y-m-d');
    //     $endDate = $dateRange ? Carbon::parse($dateRange[1])->addDay()->format('Y-m-d') : $startDate;

    //     // Get all branches
    //     $branches = StoreBranch::all();

    //     // Get products with orders
    //     $products = ProductInventory::with(['store_order_items' => function ($query) use ($startDate, $endDate, $supplierId, $branchId) {
    //         $query->whereHas('store_order', function ($subQuery) use ($startDate, $endDate, $supplierId, $branchId) {
    //             $subQuery->whereBetween('order_date', [$startDate, $endDate]);
    //             if ($supplierId) {
    //                 $subQuery->where('supplier_id', $supplierId);
    //             }
    //             if ($branchId) {
    //                 $subQuery->where('store_branch_id', $branchId);
    //             }
    //         })->with(['store_order.store_branch']);
    //     }])
    //         ->whereHas('store_order_items', function ($query) use ($startDate, $endDate) {
    //             $query->whereHas('store_order', function ($subQuery) use ($startDate, $endDate) {
    //                 $subQuery->whereBetween('order_date', [$startDate, $endDate]);
    //             });
    //         })
    //         ->get();

    //     // Transform data to include all branches
    //     $formattedData = $products->map(function ($product) {
    //         // Create quantities array indexed by branch ID
    //         $branchQuantities = $product->store_order_items
    //             ->groupBy(function ($item) {
    //                 return $item->store_order->store_branch->id;
    //             })
    //             ->mapWithKeys(function ($items, $branchId) {
    //                 return [$branchId => $items->sum('quantity_ordered')];
    //             });

    //         return [
    //             'name' => $product->name,
    //             'inventory_code' => $product->inventory_code,
    //             'branch_quantities' => $branchQuantities,
    //             'total' => $branchQuantities->sum()
    //         ];
    //     })->filter(function ($product) {
    //         return $product['total'] > 0;
    //     })->values();

    //     $pdf = PDF::loadView('item-orders-summary', [
    //         'products' => $formattedData,
    //         'branches' => $branches,
    //         'startDate' => $startDate,
    //         'endDate' => $endDate
    //     ]);

    //     $pdf->setPaper('legal', 'landscape');
    //     $pdf->setOptions([
    //         'isHtml5ParserEnabled' => true,
    //         'isRemoteEnabled' => true,
    //         'defaultFont' => 'sans-serif'
    //     ]);

    //     return $pdf->download('orders-summary.pdf');
    // }

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
                        $subQuery->whereIn('store_branch_id', $branchId);
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
