<?php

namespace App\Http\Services;

use App\Models\StoreOrder;
use App\Models\StoreBranch;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection; // Import Collection

class ConsolidatedSOReportService
{
    public function getConsolidatedSOReportData(string $orderDate, $supplierId = 'all'): array
    {
        // Fetch all active branches for dynamic columns
        $allBranches = StoreBranch::where('is_active', true)->orderBy('branch_code')->get();
        $branchCodes = $allBranches->pluck('branch_code')->toArray();
        $totalBranches = count($branchCodes);

        // Base query for StoreOrderItems
        $query = StoreOrder::query()
            ->with(['storeOrderItems.supplierItem.sapMasterfiles', 'store_branch'])
            ->whereDate('order_date', $orderDate)
            ->whereHas('storeOrderItems', function ($q) {
                $q->where('quantity_commited', '>', 0);
            });

        // Filter by supplier if not 'all'
        if ($supplierId !== 'all') {
            $query->where('supplier_id', $supplierId);
        }

        $storeOrders = $query->get();

        // Group by ItemCode and ItemDescription for the main report rows
        $reportItems = $storeOrders->flatMap(function ($order) {
            return $order->storeOrderItems->map(function ($orderItem) use ($order) {
                // Ensure supplierItem and sapMasterfile are loaded
                $supplierItem = $orderItem->supplierItem;
                $sapMasterfile = $supplierItem ? $supplierItem->sap_master_file : null;

                return [
                    'item_code' => $orderItem->item_code,
                    'item_name' => $sapMasterfile ? $sapMasterfile->ItemDescription : ($supplierItem ? $supplierItem->item_name : 'N/A'),
                    'unit' => $orderItem->uom,
                    'branch_code' => $order->store_branch->branch_code,
                    'quantity_commited' => (float) $orderItem->quantity_commited,
                    'supplier_id' => $order->supplier_id, // Include supplier_id for WHSE logic
                ];
            });
        })
        ->groupBy(function ($item) {
            return $item['item_code'] . '|' . $item['item_name'] . '|' . $item['unit'];
        })
        ->map(function ($groupedItems) use ($branchCodes, $allBranches) {
            $firstItem = $groupedItems->first();
            $row = [
                'item_code' => $firstItem['item_code'],
                'item_name' => $firstItem['item_name'],
                'unit' => $firstItem['unit'],
            ];

            // Initialize quantities for all branches to 0
            foreach ($branchCodes as $code) {
                $row[$code] = 0.0;
            }

            // Populate quantities for branches that have committed items
            foreach ($groupedItems as $item) {
                $row[$item['branch_code']] += $item['quantity_commited'];
            }

            // Calculate TOTAL (sum of all branch quantities)
            $row['total_quantity'] = array_sum(array_intersect_key($row, array_flip($branchCodes)));

            // Determine WHSE based on the first supplier_id found for the item
            // This assumes an item is typically from one supplier for a given report.
            // If an item can come from multiple suppliers within the same report, this logic might need refinement.
            $supplierCode = Supplier::find($firstItem['supplier_id'])?->supplier_code;
            $row['whse'] = $this->getWhseCode($supplierCode);

            return $row;
        })
        ->values(); // Reset keys to be a simple array

        // Define static headers
        $staticHeaders = [
            ['label' => 'ITEM CODE', 'field' => 'item_code'],
            ['label' => 'ITEM NAME', 'field' => 'item_name'],
            ['label' => 'UNIT', 'field' => 'unit'],
        ];

        // Define dynamic branch headers
        $dynamicBranchHeaders = $allBranches->map(function ($branch) {
            return ['label' => $branch->branch_code . ' Qty', 'field' => $branch->branch_code];
        })->toArray();

        // Define static trailing headers
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

    protected function getWhseCode(?string $supplierCode): string
    {
        switch ($supplierCode) {
            case 'GSI-P':
                return '03';
            case 'GSI-B':
                return '02';
            case 'PUL-O':
                return '01';
            default:
                return 'N/A'; // Or a default warehouse code
        }
    }
}
