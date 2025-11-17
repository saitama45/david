<?php

namespace App\Imports;

use App\Models\MonthEndCountTemplate;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class MonthEndCountTemplatesImport implements ToCollection, WithHeadingRow
{
    private int $createdCount = 0;
    private int $updatedCount = 0;
    private array $skippedRows = [];

    /**
    * @param Collection $rows
    */
    public function collection(Collection $rows)
    {
        if ($rows->isEmpty()) {
            throw new \Exception('No data rows found in the file. Please ensure the file is not empty and contains data below the header row.');
        }

        // Cast item_code to string before validation
        $rows = $rows->map(function ($row) {
            $row['item_code'] = (string) $row['item_code'];
            return $row;
        });

        // First, validate all rows.
        $validator = Validator::make($rows->toArray(), [
            '*.item_code' => 'required|string|max:255',
            '*.item_name' => 'required|string|max:255',
            '*.area' => 'nullable|string|max:255',
            '*.category_2' => 'nullable|string|max:255',
            '*.category_1' => 'nullable|string|max:255',
            '*.packaging' => 'nullable|string|max:255',
            '*.conversion' => 'nullable|integer',
            '*.bulk_uom' => 'nullable|string|max:255',
            '*.loose_uom' => 'nullable|string|max:255',
        ], [
            '*.item_code.required' => 'The "item_code" column is required for all rows.',
            '*.item_name.required' => 'The "item_name" column is required for all rows.',
        ]);

        // If validation fails, throw an exception with the first error message.
        if ($validator->fails()) {
            throw new \Exception($validator->errors()->first());
        }

        // If validation passes, process the models.
        foreach ($rows as $index => $row) {
            // Skip row if both item_code and item_name are missing, as an extra safeguard.
            if (empty($row['item_code']) && empty($row['item_name'])) {
                continue;
            }

            $itemCode = $row['item_code'];
            $bulkUom = $row['bulk_uom'] ?? null;

            $template = MonthEndCountTemplate::where('item_code', $itemCode)
                ->where('uom', $bulkUom)
                ->first();

            $dataToCompare = [
                'item_name'        => $row['item_name'],
                'area'             => $row['area'] ?? null,
                'category_2'       => $row['category_2'] ?? null,
                'category'         => $row['category_1'] ?? null,
                'packaging_config' => $row['packaging'] ?? null,
                'config'           => $row['conversion'] ?? null,
                'loose_uom'        => $row['loose_uom'] ?? null,
            ];

            if ($template) {
                $isChanged = false;
                foreach ($dataToCompare as $key => $value) {
                    if ($template->{$key} != $value) {
                        $isChanged = true;
                        break;
                    }
                }

                if ($isChanged) {
                    $template->update(array_merge($dataToCompare, ['updated_by' => Auth::id()]));
                    $this->updatedCount++;
                } else {
                    $this->skippedRows[] = [
                        'row_number' => $index + 2,
                        'item_code' => $itemCode,
                        'bulk_uom' => $bulkUom,
                        'reason' => 'Item code with the same Bulk UOM already exists and has no changes.',
                    ];
                }
            } else {
                MonthEndCountTemplate::create([
                    'item_code'        => $itemCode,
                    'uom'              => $bulkUom,
                    'item_name'        => $row['item_name'],
                    'area'             => $row['area'] ?? null,
                    'category_2'       => $row['category_2'] ?? null,
                    'category'         => $row['category_1'] ?? null,
                    'packaging_config' => $row['packaging'] ?? null,
                    'config'           => $row['conversion'] ?? null,
                    'loose_uom'        => $row['loose_uom'] ?? null,
                    'created_by'       => Auth::id(),
                ]);
                $this->createdCount++;
            }
        }

        if ($this->createdCount === 0 && $this->updatedCount === 0) {
            $message = 'The file was processed, but no valid rows were found to import or update. Please check the file content.';
            if (!empty($this->skippedRows)) {
                $message .= ' ' . count($this->skippedRows) . ' rows were skipped because they already exist with no changes.';
            }
            throw new \Exception($message);
        }

        if (!empty($this->skippedRows)) {
            $skippedRowsDetails = collect($this->skippedRows)->map(function ($skipped) {
                return "Row {$skipped['row_number']}: Item Code {$skipped['item_code']} with Bulk UOM " . ($skipped['bulk_uom'] ?? 'N/A');
            })->implode('; ');

            $warningMessage = count($this->skippedRows) . " items were skipped because they already exist with no changes. Details: " . $skippedRowsDetails;
            session()->flash('warning', $warningMessage);
        }
    }

    public function getCreatedCount(): int
    {
        return $this->createdCount;
    }

    public function getUpdatedCount(): int
    {
        return $this->updatedCount;
    }

    public function getSkippedRows(): array
    {
        return $this->skippedRows;
    }
}
