<?php

namespace App\Imports;

use App\Models\MonthEndCountItem;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
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

        foreach ($rows as $row) {
            // Skip empty rows
            if ($row->filter()->isEmpty()) {
                continue;
            }

            // Skip rows missing required fields or values
            if (!isset($row['itemcode']) || empty($row['itemcode']) ||
                !isset($row['total_qty']) || $row['total_qty'] === null || $row['total_qty'] === '' ||
                !isset($row['sap_masterfile_id']) || empty($row['sap_masterfile_id'])) {
                // Silently skip this row as per user's instruction
                continue;
            }

            $itemCode = $row['itemcode'];
            $totalQty = (float) $row['total_qty'];
            $sapMasterfileId = (int) $row['sap_masterfile_id'];
            $remarks = $row['remarks'] ?? null; // Allow null remarks
            $packagingConfig = $row['packaging_config'] ?? null;
            $config = $row['config'] ?? null;
            $uom = $row['uom'] ?? null;
            $bulkQty = (float) ($row['bulk_qty'] ?? 0);
            $looseQty = (float) ($row['loose_qty'] ?? 0);
            $looseUom = $row['loose_uom'] ?? null;
            $itemName = $row['item_name'] ?? 'N/A';

            if ($totalQty < 0) {
                // If total_qty is negative, we still want to flag this as an error
                $this->errors[] = "Invalid total_qty for ItemCode {$itemCode}: Quantity cannot be negative.";
                continue;
            }

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
                    'packaging_config' => $packagingConfig,
                    'config' => $config,
                    'uom' => $uom,
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
