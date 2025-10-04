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
            throw new Exception("User not authenticated for import.");
        }

        foreach ($rows as $rowIndex => $row) {
            // Skip empty rows
            if ($row->filter()->isEmpty()) {
                continue;
            }

            // Skip rows missing required fields or values
            if (!isset($row['itemcode']) || empty($row['itemcode']) ||
                !isset($row['uom']) || empty($row['uom']) ||
                !isset($row['total_qty']) || $row['total_qty'] === null || $row['total_qty'] === '') {
                Log::warning("MonthEndCountImport: Skipping row due to missing required fields", [
                    'row_index' => $rowIndex,
                    'itemcode' => $row['itemcode'] ?? 'missing',
                    'uom' => $row['uom'] ?? 'missing',
                    'total_qty' => $row['total_qty'] ?? 'missing'
                ]);
                continue;
            }

            $itemCode = $row['itemcode'];
            $uom = $row['uom'];
            $totalQty = (float) $row['total_qty'];
            $remarks = $row['remarks'] ?? null;
            $packagingConfig = $row['packaging_config'] ?? null;
            $config = $row['config'] ?? null;
            $bulkQty = (float) ($row['bulk_qty'] ?? 0);
            $looseQty = (float) ($row['loose_qty'] ?? 0);
            $looseUom = $row['loose_uom'] ?? null;
            $itemName = $row['item_name'] ?? 'N/A';
            $area = $row['area'] ?? null;
            $category2 = $row['category2'] ?? null;
            $category = $row['category'] ?? null;
            $brand = $row['brand'] ?? null;
            $currentSoh = (float) ($row['current_soh'] ?? 0);

            // Find SAP Masterfile by ItemCode and UOM (AltUOM)
            $sapMasterfile = SAPMasterfile::where('ItemCode', $itemCode)
                ->where('AltUOM', $uom)
                ->first();

            if (!$sapMasterfile) {
                Log::error("MonthEndCountImport: SAP Masterfile not found", [
                    'row_index' => $rowIndex,
                    'itemcode' => $itemCode,
                    'uom' => $uom
                ]);
                $this->errors[] = "SAP Masterfile not found for ItemCode {$itemCode} with UOM {$uom} (Row " . ($rowIndex + 2) . ")";
                continue;
            }

            $sapMasterfileId = $sapMasterfile->id;

            if ($totalQty < 0) {
                $this->errors[] = "Invalid total_qty for ItemCode {$itemCode}: Quantity cannot be negative (Row " . ($rowIndex + 2) . ")";
                continue;
            }

            Log::info("MonthEndCountImport: Processing row", [
                'row_index' => $rowIndex,
                'itemcode' => $itemCode,
                'uom' => $uom,
                'sap_masterfile_id' => $sapMasterfileId,
                'total_qty' => $totalQty
            ]);

            // Save to MonthEndCountItem staging table
            MonthEndCountItem::updateOrCreate(
                [
                    'month_end_schedule_id' => $this->scheduleId,
                    'branch_id' => $this->branchId,
                    'sap_masterfile_id' => $sapMasterfileId,
                ],
                [
                    'item_code' => $itemCode,
                    'item_name' => $itemName,
                    'area' => $area,
                    'category2' => $category2,
                    'category' => $category,
                    'brand' => $brand,
                    'packaging_config' => $packagingConfig,
                    'config' => $config,
                    'uom' => $uom,
                    'current_soh' => $currentSoh,
                    'bulk_qty' => $bulkQty,
                    'loose_qty' => $looseQty,
                    'loose_uom' => $looseUom,
                    'remarks' => $remarks,
                    'total_qty' => $totalQty,
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
