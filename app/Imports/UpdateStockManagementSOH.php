<?php

namespace App\Imports;

use App\Http\Services\SohAdjustmentService;
use App\Models\SAPMasterfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Validators\Failure;

class UpdateStockManagementSOH implements ToCollection, WithHeadingRow
{
    /**
     * @param Collection $collection
     */

    protected $branch;
    protected $importedData = [];
    protected $errors = [];

    public function __construct($branch)
    {
        $this->branch = $branch;
    }

    /**
     * Every row with a variance becomes an SOH adjustment waiting for approval, as one
     * typed on the SOH Adjustment page does. The ID must be the SAP row of the Item Code
     * beside it: a file from before the SAP Masterlist carries ids of another table.
     */
    public function collection(Collection $collection)
    {
        foreach ($collection as $index => $row) {
            $variance = $row['variance'] ?? null;

            if (! is_numeric($variance) || (float) $variance == 0.0) {
                continue;
            }

            try {
                $unitRow = SAPMasterfile::find($row['id'] ?? null);
                $itemCode = trim((string) ($row['item_code'] ?? $row['inventory_code'] ?? ''));

                if (! $unitRow || strcasecmp(trim((string) $unitRow->ItemCode), $itemCode) !== 0) {
                    throw ValidationException::withMessages([
                        'id' => 'The ID and Item Code are not an item of the SAP Masterlist. Download the SOH Update file again.',
                    ]);
                }

                $adjustment = app(SohAdjustmentService::class)->requestDifference(
                    $unitRow, (int) $this->branch, (float) $variance, $row['remarks'] ?? null, Auth::user()
                );

                $this->importedData[] = ['id' => $adjustment->id, 'item_code' => $unitRow->ItemCode, 'variance' => (float) $variance];
            } catch (ValidationException $e) {
                // Heading row + 1-based rows: the first data row is row 2 of the file.
                $this->errors[] = 'Row ' . ($index + 2) . ': ' . collect($e->errors())->flatten()->first();
            }
        }
    }

    public function getImportedData()
    {
        return $this->importedData;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function onFailure(Failure ...$failures)
    {
        foreach ($failures as $failure) {
            $this->errors[] = "Import failure: " . implode(', ', $failure->errors());
        }
    }
}
