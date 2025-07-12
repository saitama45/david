<?php

namespace App\Imports;

use App\Models\SupplierItems;
use App\Models\SapMasterfile;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log; // Ensure Log is imported
use Carbon\Carbon;

class SupplierItemsImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    protected $skippedDetails = []; // Property to store details of skipped rows
    protected $processedCount = 0;
    protected $skippedEmptyKeysCount = 0;
    protected $skippedBySapValidationCount = 0;

    // Remove the reset from the collection method.
    // Counters and skippedDetails are initialized when the object is created.
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            $itemCode = trim($row['item_code'] ?? '');
            $supplierCode = trim($row['supplier_code'] ?? '');
            $unit = trim($row['unit'] ?? ''); // Excel's UNIT column

            // 1. Basic validation: Skip if ItemCode or SupplierCode are empty
            if (empty($itemCode) || empty($supplierCode)) {
                $this->skippedEmptyKeysCount++;
                $reason = 'Empty ItemCode or SupplierCode';
                $details = [
                    'ItemCode' => $itemCode,
                    'SupplierCode' => $supplierCode
                ];
                $this->addSkippedDetail($row, $reason, $details);
                // Also log to file for debugging
                Log::warning('SupplierItems Import Skipped (Empty Keys): ' . $reason . ' - ' . json_encode($details) . ' - Original Row: ' . json_encode($row->toArray()));
                continue;
            }

            // 2. SAP Validation: Check if ItemCode and AltUOM (from Excel's UNIT) exist in sap_masterfiles
            $sapMasterfileExists = SapMasterfile::where('ItemCode', $itemCode)
                                                ->where('AltUOM', $unit)
                                                ->exists();

            if (!$sapMasterfileExists) {
                $this->skippedBySapValidationCount++;
                $reason = 'ItemCode and AltUOM (UNIT) combination not found in SAP Masterfile';
                $details = [
                    'ItemCode' => $itemCode,
                    'AltUOM_from_Excel' => $unit
                ];
                $this->addSkippedDetail($row, $reason, $details);
                // Also log to file for debugging
                Log::warning('SupplierItems Import Skipped (SAP Validation): ' . $reason . ' - ' . json_encode($details) . ' - Original Row: ' . json_encode($row->toArray()));
                continue;
            }

            // If valid, proceed with data preparation and updateOrCreate
            $category = trim($row['category'] ?? '');
            $brand = trim($row['brand'] ?? '');
            $classification = trim($row['classification'] ?? '');
            $itemName = trim($row['item_name'] ?? '');
            $packagingConfig = trim($row['packaging_configuration'] ?? '');
            $cost = (float) ($row['cost'] ?? 0.00);
            $srp = (float) ($row['srp'] ?? 0.00);
            
            $isActive = filter_var($row['active'] ?? 1, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (is_null($isActive)) {
                $isActive = (int)($row['active'] == 1);
            }
            $isActive = (int)$isActive;

            $data = [
                'category'          => $category,
                'brand'             => $brand,
                'classification'    => $classification,
                'ItemCode'          => $itemCode,
                'item_name'         => $itemName,
                'packaging_config'  => $packagingConfig,
                'config'            => 0.00, // Make sure this value is correct, seems fixed at 0.00
                'uom'               => $unit,
                'cost'              => $cost,
                'srp'               => $srp,
                'SupplierCode'      => $supplierCode,
                'is_active'         => $isActive,
            ];

            $uniqueAttributes = [
                'ItemCode' => $itemCode,
                'SupplierCode' => $supplierCode,
            ];

            try {
                SupplierItems::updateOrCreate($uniqueAttributes, $data);
                $this->processedCount++;

            } catch (\Exception $e) {
                // Log and store details for database errors during updateOrCreate
                $this->addSkippedDetail($row, 'Database Error: ' . $e->getMessage(), [
                    'ItemCode' => $itemCode,
                    'SupplierCode' => $supplierCode,
                    'Error' => $e->getMessage(),
                ]);
                Log::error('SupplierItems Import: Error processing row for ItemCode: ' . $itemCode . ', SupplierCode: ' . $supplierCode . '. Error: ' . $e->getMessage(), [
                    'row_data' => $data,
                    'exception' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    /**
     * Adds details of a skipped row to the collection.
     * @param \Illuminate\Support\Collection $row The original row data from Excel.
     * @param string $reason The reason for skipping.
     * @param array $specificDetails Any specific details related to the skip.
     */
    protected function addSkippedDetail(\Illuminate\Support\Collection $row, $reason, $specificDetails = [])
    {
        $this->skippedDetails[] = [
            'original_row' => $row->toArray(), // Store the original row as an array
            'reason' => $reason,
            'details' => $specificDetails,
            'timestamp' => Carbon::now()->toDateTimeString(),
        ];
    }

    // ... (Getter methods remain the same)
    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    public function getSkippedEmptyKeysCount(): int
    {
        return $this->skippedEmptyKeysCount;
    }

    public function getSkippedBySapValidationCount(): int
    {
        return $this->skippedBySapValidationCount;
    }

    public function getSkippedDetails(): array
    {
        return $this->skippedDetails;
    }

    public function batchSize(): int
    {
        return 1;
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}