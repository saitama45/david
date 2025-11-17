<?php

namespace App\Imports;

use App\Models\MonthEndCountItem;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\SAPMasterfile;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class MonthEndCountImport implements ToCollection, WithHeadingRow
{
    protected $branchId;
    protected $scheduleId;
    protected $errors = [];

    public function __construct(int $branchId, int $scheduleId)
    {
        $this->branchId = $branchId;
        $this->scheduleId = $scheduleId;
    }

    /**
    * @param Collection $collection
    */
    public function collection(Collection $rows)
    {
        $user = Auth::user();
        if (!$user) {
            throw new \Exception("User not authenticated for import.");
        }

        foreach ($rows as $rowIndex => $row) {
            // Skip empty rows
            if ($row->filter()->isEmpty()) {
                continue;
            }

            // Use snake_cased headers from the downloaded template
            $itemCode = $row['item_code'] ?? null;
            $bulkUom = $row['bulk_uom'] ?? null;

            // Skip rows where user did not enter any quantity
            if (!isset($row['bulk_qty']) && !isset($row['loose_qty'])) {
                continue;
            }

            // Skip rows missing required identifiers
            if (empty($itemCode)) {
                Log::warning("MonthEndCountImport: Skipping row due to missing item_code", [
                    'row_index' => $rowIndex,
                    'item_code' => $itemCode ?? 'missing',
                ]);
                $this->errors[] = "Skipping row " . ($rowIndex + 2) . " due to missing Item Code.";
                continue;
            }

            // Read quantities and other data
            $bulkQty = (float) ($row['bulk_qty'] ?? 0);
            $looseQty = (float) ($row['loose_qty'] ?? 0);
            $config = (float) ($row['conversion'] ?? 0);
            $remarks = $row['remarks'] ?? null;

            // Calculate total_qty
            $totalQty = 0;
            if ($config > 0) {
                $totalQty = $bulkQty + ($looseQty / $config);
            } else {
                $totalQty = $bulkQty + $looseQty;
            }

            // Find SAP Masterfile by ItemCode and UOM (Bulk UOM)
            $sapMasterfile = SAPMasterfile::where('ItemCode', $itemCode)
                ->where('AltUOM', $bulkUom)
                ->first();

            if (!$sapMasterfile) {
                Log::error("MonthEndCountImport: SAP Masterfile not found", [
                    'row_index' => $rowIndex,
                    'itemcode' => $itemCode,
                    'uom' => $bulkUom
                ]);
                $this->errors[] = "SAP Masterfile not found for ItemCode {$itemCode} with UOM {$bulkUom} (Row " . ($rowIndex + 2) . ")";
                continue;
            }

            $sapMasterfileId = $sapMasterfile->id;

            // Save to MonthEndCountItem staging table
            MonthEndCountItem::updateOrCreate(
                [
                    'month_end_schedule_id' => $this->scheduleId,
                    'branch_id' => $this->branchId,
                    'sap_masterfile_id' => $sapMasterfileId,
                ],
                [
                    'item_code' => $itemCode,
                    'item_name' => $row['item_name'] ?? 'N/A',
                    'area' => $row['area'] ?? null,
                    'category2' => $row['category_2'] ?? null, // Fixed key
                    'category' => $row['category_1'] ?? null, // Fixed key
                    'brand' => null, // Removed 'brand' from import
                    'packaging_config' => $row['packaging'] ?? null, // Fixed key
                    'config' => $config,
                    'uom' => $bulkUom,
                    'current_soh' => (float) ($row['current_soh'] ?? 0),
                    'bulk_qty' => $bulkQty,
                    'loose_qty' => $looseQty,
                    'loose_uom' => $row['loose_uom'] ?? null,
                    'remarks' => $remarks,
                    'total_qty' => $totalQty, // Use calculated value
                    'status' => 'uploaded',
                    'created_by' => $user->id,
                ]
            );
        }

        if (!empty($this->errors)) {
            throw ValidationException::withMessages(['excel_import' => $this->errors]);
        }
    }
}
