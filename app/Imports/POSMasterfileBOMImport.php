<?php

namespace App\Imports;

use App\Models\POSMasterfileBOM;
use App\Models\POSMasterfile; // Import POSMasterfile model for validation
use App\Models\SAPMasterfile; // Import SAPMasterfile model for validation
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str; // Import Str for slugging/normalizing column names
use Illuminate\Support\Facades\Auth; // Import Auth facade

class POSMasterfileBOMImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    protected $skippedDetails = [];
    protected $processedCount = 0;
    protected $skippedEmptyKeysCount = 0;
    protected $skippedByPosMasterfileValidationCount = 0; // New counter for POS Masterfile validation
    protected $skippedBySapMasterfileValidationCount = 0; // New counter for SAP Masterfile validation

    // Constructor can be used if you need to pass assigned POS codes for authorization, similar to SupplierItemsImport
    // public function __construct(array $assignedPOSCodes = [])
    // {
    //     $this->assignedCodes = $assignedCodes; // Renamed for clarity
    // }

    /**
     * @param Collection $rows
     *
     * @return void
     */
    public function collection(Collection $rows)
    {
        Log::debug("POSMasterfileBOMImport: Starting collection processing.");
        Log::debug("POSMasterfileBOMImport: Raw collection received (" . $rows->count() . " rows): " . json_encode($rows->toArray()));

        // Log the count before deduplication (if it were still active)
        Log::debug("POSMasterfileBOMImport: Rows before deduplication: " . $rows->count());

        $upsertBatchSize = 100; 

        // We are processing all rows, as deduplication was removed.
        $rowsToProcess = $rows; 

        // Log the count of rows that will be processed
        Log::debug("POSMasterfileBOMImport: Rows to process (all rows): " . $rowsToProcess->count());


        $dataForCurrentChunk = [];

        foreach ($rowsToProcess as $row) {
            $getCellValue = function($row, $keys) {
                foreach ($keys as $key) {
                    if (isset($row[$key]) && (is_string($row[$key]) ? trim($row[$key]) !== '' : $row[$key] !== null)) {
                        return trim($row[$key]);
                    }
                }
                return null;
            };

            $posCode = $getCellValue($row, ['pos_code', 'POS Code']);
            $itemCode = $getCellValue($row, ['item_code', 'ITEM CODE']);
            $posDescription = $getCellValue($row, ['pos_description', 'POS Description', 'Item Description']);
            $assembly = $getCellValue($row, ['assembly', 'ASSEMBLY']);
            $itemDescription = $getCellValue($row, ['item_description', 'PRODUCT DESCRIPTION']);
            $recPercent = is_numeric($getCellValue($row, ['rec_percent', 'REC%'])) ? (float)$getCellValue($row, ['rec_percent', 'REC%']) : null;
            $recipeQty = is_numeric($getCellValue($row, ['recipe_qty', 'RECIPE QTY'])) ? (float)$getCellValue($row, ['recipe_qty', 'RECIPE QTY']) : null;
            $recipeUOM = $getCellValue($row, ['recipe_uom', 'RECIPE UOM', 'UOM']); 
            $bomQty = is_numeric($getCellValue($row, ['bom_qty', 'BOM QTY'])) ? (float)$getCellValue($row, ['bom_qty', 'BOM QTY']) : null;
            $bomUOM = $getCellValue($row, ['bom_uom', 'BOM UOM', 'UOM']); 
            $unitCost = is_numeric($getCellValue($row, ['unit_cost', 'UNIT COST'])) ? (float)$getCellValue($row, ['unit_cost', 'UNIT COST']) : null;
            $totalCost = is_numeric($getCellValue($row, ['total_cost', 'TOTAL COST'])) ? (float)$getCellValue($row, ['total_cost', 'TOTAL COST']) : null;


            Log::debug("POSMasterfileBOMImport: Extracted for row. POSCode: '{$posCode}', ItemCode: '{$itemCode}', Assembly: '{$assembly}', RecipeQty: '{$recipeQty}', UnitCost: '{$unitCost}', ItemDescription: '{$itemDescription}'");

            if (empty($posCode) || empty($itemCode) || empty($assembly)) { // Added Assembly to basic validation
                $this->skippedEmptyKeysCount++;
                $reason = 'Empty POS Code, Item Code, or Assembly';
                $details = ['POSCode' => $posCode, 'ItemCode' => $itemCode, 'Assembly' => $assembly];
                $this->addSkippedDetail($row, $reason, $details);
                Log::warning('POSMasterfileBOM Import Skipped (Empty Keys): ' . $reason . ' - ' . json_encode($details) . ' - Original Row: ' . json_encode($row->toArray()));
                continue;
            }

            $posMasterfileExists = POSMasterfile::where('ItemCode', $posCode)->exists();
            if (!$posMasterfileExists) {
                $this->skippedByPosMasterfileValidationCount++;
                $reason = 'POS Code not found in POS Masterfile';
                $details = ['POSCode' => $posCode, 'ExcelRow' => $row->toArray()];
                $this->addSkippedDetail($row, $reason, $details);
                Log::warning('POSMasterfileBOM Import Skipped (POS Masterfile Validation): ' . $reason . ' - ' . json_encode($details) . ' - Original Row: ' . json_encode($row->toArray()));
                continue;
            }

            $sapMasterfileExists = SAPMasterfile::where('ItemCode', $itemCode)->exists();
            if (!$sapMasterfileExists) {
                $this->skippedBySapMasterfileValidationCount++;
                $reason = 'Item Code not found in SAP Masterfile';
                $details = ['ItemCode' => $itemCode, 'ExcelRow' => $row->toArray()];
                $this->addSkippedDetail($row, $reason, $details);
                Log::warning('POSMasterfileBOM Import Skipped (SAP Masterfile Validation): ' . $reason . ' - ' . json_encode($details) . ' - Original Row: ' . json_encode($row->toArray()));
                continue;
            }

            if ($recipeQty === null || $recipeQty < 0) {
                $reason = 'Recipe Quantity is missing or invalid (negative)';
                $details = ['POSCode' => $posCode, 'ItemCode' => $itemCode, 'RecipeQty' => $recipeQty, 'ExcelRow' => $row->toArray()];
                $this->addSkippedDetail($row, $reason, $details);
                Log::warning('POSMasterfileBOM Import Skipped (Invalid RecipeQty): ' . $reason . ' - ' . json_encode($details) . ' - Original Row: ' . json_encode($row->toArray()));
                continue;
            }
            if ($bomQty === null || $bomQty < 0) {
                $reason = 'BOM Quantity is missing or invalid (negative)';
                $details = ['POSCode' => $posCode, 'ItemCode' => $itemCode, 'BOMQty' => $bomQty, 'ExcelRow' => $row->toArray()];
                $this->addSkippedDetail($row, $reason, $details);
                Log::warning('POSMasterfileBOM Import Skipped (Invalid BOMQty): ' . $reason . ' - ' . json_encode($details) . ' - Original Row: ' . json_encode($row->toArray()));
                continue;
            }
            if ($unitCost === null || $unitCost < 0) {
                $reason = 'Unit Cost is missing or invalid (negative)';
                $details = ['POSCode' => $posCode, 'ItemCode' => $itemCode, 'UnitCost' => $unitCost, 'ExcelRow' => $row->toArray()];
                $this->addSkippedDetail($row, $reason, $details);
                Log::warning('POSMasterfileBOM Import Skipped (Invalid UnitCost): ' . $reason . ' - ' . json_encode($details) . ' - Original Row: ' . json_encode($row->toArray()));
                continue;
            }
            if ($totalCost === null || $totalCost < 0) {
                $reason = 'Total Cost is missing or invalid (negative)';
                $details = ['POSCode' => $posCode, 'ItemCode' => $itemCode, 'TotalCost' => $totalCost, 'ExcelRow' => $row->toArray()];
                $this->addSkippedDetail($row, $reason, $details);
                Log::warning('POSMasterfileBOM Import Skipped (Invalid TotalCost): ' . $reason . ' - ' . json_encode($details) . ' - Original Row: ' . json_encode($row->toArray()));
                continue;
            }

            $dataForCurrentChunk[] = [
                'POSCode' => $posCode,
                'POSDescription' => $posDescription,
                'Assembly' => $assembly,
                'ItemCode' => $itemCode,
                'ItemDescription' => $itemDescription,
                'RecPercent' => $recPercent,
                'RecipeQty' => $recipeQty,
                'RecipeUOM' => $recipeUOM,
                'BOMQty' => $bomQty,
                'BOMUOM' => $bomUOM,
                'UnitCost' => $unitCost,
                'TotalCost' => $totalCost,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $this->processedCount++;
        }

        if (!empty($dataForCurrentChunk)) {
            foreach (array_chunk($dataForCurrentChunk, $upsertBatchSize) as $miniBatch) {
                // CRITICAL FIX: Changed uniqueBy to include 'Assembly'
                $uniqueBy = ['POSCode', 'ItemCode', 'Assembly']; 

                $updateColumns = [
                    'POSDescription', 'ItemDescription', 'RecPercent',
                    'RecipeQty', 'RecipeUOM', 'BOMQty', 'BOMUOM', 'UnitCost', 'TotalCost',
                    'updated_by', 'updated_at'
                ];

                DB::table('pos_masterfiles_bom')->upsert($miniBatch, $uniqueBy, $updateColumns);
            }
        }
    }

    protected function addSkippedDetail(\Illuminate\Support\Collection $row, $reason, $specificDetails = [])
    {
        $this->skippedDetails[] = [
            'original_row' => $row->toArray(),
            'reason' => $reason,
            'details' => $specificDetails,
            'timestamp' => Carbon::now()->toDateTimeString(),
        ];
    }

    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    public function getSkippedEmptyKeysCount(): int
    {
        return $this->skippedEmptyKeysCount;
    }

    public function getSkippedByPosMasterfileValidationCount(): int
    {
        return $this->skippedByPosMasterfileValidationCount;
    }

    public function getSkippedBySapMasterfileValidationCount(): int
    {
        return $this->skippedBySapMasterfileValidationCount;
    }

    public function getSkippedDetails(): array
    {
        return $this->skippedDetails;
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}
