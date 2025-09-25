<?php

namespace App\Http\Controllers;

use App\Exports\CSMassCommitsExport;
use App\Models\StoreBranch;
use App\Models\Supplier;
use App\Models\StoreOrder;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class CSMassCommitsController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $orderDate = $request->input('order_date', Carbon::today()->format('Y-m-d'));
        $supplierCode = $request->input('supplier_id', 'all');

        $userSuppliers = $user->suppliers()->get();
        $suppliers = $userSuppliers->map(function ($supplier) {
            return [
                'label' => $supplier->name . ' (' . $supplier->supplier_code . ')',
                'value' => $supplier->supplier_code,
            ];
        });

        $supplierId = ($supplierCode === 'all') ? 'all' : Supplier::where('supplier_code', $supplierCode)->first()?->id;

        $dayName = Carbon::parse($orderDate)->format('l');
        $user->load('store_branches.delivery_schedules');

        $branchesForReport = $user->store_branches->filter(function ($branch) use ($dayName, $supplierCode, $userSuppliers) {
            $schedulesOnDay = $branch->delivery_schedules->where('day', strtoupper($dayName));
            if ($schedulesOnDay->isEmpty()) {
                return false;
            }

            if ($supplierCode !== 'all') {
                return $schedulesOnDay->contains(function ($schedule) use ($supplierCode) {
                    return $schedule->pivot->variant === $supplierCode;
                });
            } else {
                $userSupplierCodes = $userSuppliers->pluck('supplier_code');
                return $schedulesOnDay->contains(function ($schedule) use ($userSupplierCodes) {
                    return $userSupplierCodes->contains($schedule->pivot->variant);
                });
            }
        });

        $branches = $branchesForReport->pluck('name', 'id');
        $branchIdsForReport = $branchesForReport->pluck('id');

        $reportData = $this->getCSMassCommitsData(
            $orderDate,
            $supplierId,
            $branchIdsForReport
        );

        return Inertia::render('CSMassCommits/Index', [
            'filters' => [
                'order_date' => $orderDate,
                'supplier_id' => $supplierCode,
            ],
            'branches' => $branches,
            'suppliers' => $suppliers,
            'report' => $reportData['report'],
            'dynamicHeaders' => $reportData['dynamicHeaders'],
            'totalBranches' => $reportData['totalBranches'],
        ]);
    }

    public function export(Request $request)
    {
        $user = Auth::user();
        $orderDate = $request->input('order_date', Carbon::today()->format('Y-m-d'));
        $supplierCode = $request->input('supplier_id', 'all');

        $supplierId = ($supplierCode === 'all') ? 'all' : Supplier::where('supplier_code', $supplierCode)->first()?->id;

        $userSuppliers = $user->suppliers()->get();
        $dayName = Carbon::parse($orderDate)->format('l');
        $user->load('store_branches.delivery_schedules');

        $branchesForReport = $user->store_branches->filter(function ($branch) use ($dayName, $supplierCode, $userSuppliers) {
            $schedulesOnDay = $branch->delivery_schedules->where('day', strtoupper($dayName));
            if ($schedulesOnDay->isEmpty()) {
                return false;
            }

            if ($supplierCode !== 'all') {
                return $schedulesOnDay->contains(function ($schedule) use ($supplierCode) {
                    return $schedule->pivot->variant === $supplierCode;
                });
            } else {
                $userSupplierCodes = $userSuppliers->pluck('supplier_code');
                return $schedulesOnDay->contains(function ($schedule) use ($userSupplierCodes) {
                    return $userSupplierCodes->contains($schedule->pivot->variant);
                });
            }
        });
        $branchIdsForReport = $branchesForReport->pluck('id');

        $reportData = $this->getCSMassCommitsData(
            $orderDate,
            $supplierId,
            $branchIdsForReport
        );

        return Excel::download(
            new CSMassCommitsExport(
                $reportData['report'],
                $reportData['dynamicHeaders'],
                $reportData['totalBranches'],
                $orderDate
            ),
            'cs-mass-commits-' . Carbon::parse($orderDate)->format('Y-m-d') . '.xlsx'
        );
    }

    private function getCSMassCommitsData(string $orderDate, $supplierId = 'all', ?Collection $branchIds = null): array
    {
        $branchQuery = StoreBranch::where('is_active', true)->orderBy('brand_code');
        if ($branchIds && $branchIds->isNotEmpty()) {
            $branchQuery->whereIn('id', $branchIds);
        }
        $allBranches = $branchQuery->get();
        $brandCodes = $allBranches->pluck('brand_code')->toArray();
        $totalBranches = count($brandCodes);

        $query = StoreOrder::query()
            ->with(['storeOrderItems.supplierItem.sapMasterfiles', 'store_branch'])
            ->whereDate('order_date', $orderDate)
            ->whereHas('storeOrderItems', function ($q) {
                $q->where('quantity_commited', '>', 0);
            });

        if ($supplierId !== 'all') {
            $query->where('supplier_id', $supplierId);
        }

        if ($branchIds && $branchIds->isNotEmpty()) {
            $query->whereIn('store_branch_id', $branchIds);
        }

        $storeOrders = $query->get();

        $reportItems = $storeOrders->flatMap(function ($order) {
            return $order->storeOrderItems->map(function ($orderItem) use ($order) {
                $supplierItem = $orderItem->supplierItem;
                $sapMasterfile = $supplierItem ? $supplierItem->sap_master_file : null;

                return [
                    'category' => $supplierItem ? $supplierItem->category : 'N/A',
                    'item_code' => $orderItem->item_code,
                    'item_name' => $sapMasterfile ? $sapMasterfile->ItemDescription : ($supplierItem ? $supplierItem->item_name : 'N/A'),
                    'unit' => $orderItem->uom,
                    'brand_code' => $order->store_branch->brand_code,
                    'quantity_commited' => (float) $orderItem->quantity_commited,
                    'supplier_id' => $order->supplier_id,
                ];
            });
        })
        ->groupBy(function ($item) {
            return $item['category'] . '|' . $item['item_code'] . '|' . $item['item_name'] . '|' . $item['unit'];
        })
        ->map(function ($groupedItems) use ($brandCodes, $allBranches) {
            $firstItem = $groupedItems->first();
            $row = [
                'category' => $firstItem['category'],
                'item_code' => $firstItem['item_code'],
                'item_name' => $firstItem['item_name'],
                'unit' => $firstItem['unit'],
            ];

            foreach ($brandCodes as $code) {
                $row[$code] = 0.0;
            }

            foreach ($groupedItems as $item) {
                $row[$item['brand_code']] += $item['quantity_commited'];
            }

            $row['total_quantity'] = array_sum(array_intersect_key($row, array_flip($brandCodes)));

            $supplierCode = Supplier::find($firstItem['supplier_id'])?->supplier_code;
            $row['whse'] = $this->getWhseCode($supplierCode);

            return $row;
        })
        ->values();

        $staticHeaders = [
            ['label' => 'CATEGORY', 'field' => 'category'],
            ['label' => 'ITEM CODE', 'field' => 'item_code'],
            ['label' => 'ITEM NAME', 'field' => 'item_name'],
            ['label' => 'UNIT', 'field' => 'unit'],
        ];

        $dynamicBranchHeaders = $allBranches->map(function ($branch) {
            return ['label' => $branch->brand_code . ' Qty', 'field' => $branch->brand_code];
        })->toArray();

        $trailingHeaders = [
            ['label' => 'TOTAL', 'field' => 'total_quantity'],
            ['label' => 'WHSE', 'field' => 'whse'],
        ];

        $allHeaders = array_merge($staticHeaders, $dynamicBranchHeaders, $trailingHeaders);

        return [
            'report' => $reportItems,
            'dynamicHeaders' => $allHeaders,
            'totalBranches' => $totalBranches,
        ];
    }

    private function getWhseCode(?string $supplierCode): string
    {
        switch ($supplierCode) {
            case 'GSI-P':
                return '03';
            case 'GSI-B':
                return '03';
            case 'PUL-O':
                return '01';
            default:
                return 'N/A';
        }
    }

    public function updateCommit(Request $request)
    {
        $validated = $request->validate([
            'order_date' => 'required|date',
            'item_code' => 'required|string|exists:supplier_items,ItemCode',
            'brand_code' => 'required|string|exists:store_branches,brand_code',
            'new_quantity' => 'required|numeric|min:0',
        ]);

        $orderItem = \App\Models\StoreOrderItem::where('item_code', $validated['item_code'])
            ->whereHas('store_order', function ($query) use ($validated) {
                $query->whereDate('order_date', $validated['order_date'])
                      ->whereHas('store_branch', function ($subQuery) use ($validated) {
                          $subQuery->where('brand_code', $validated['brand_code']);
                      });
            })
            ->first();

        if (!$orderItem) {
            return response()->json(['message' => 'Order item not found for the specified criteria.'], 404);
        }

        $orderItem->update(['quantity_commited' => $validated['new_quantity']]);

        return redirect()->back()->with('success', 'Commit quantity updated successfully.');
    }
}