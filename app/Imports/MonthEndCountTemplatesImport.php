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
    /**
    * @param Collection $rows
    */
    public function collection(Collection $rows)
    {
        if ($rows->isEmpty()) {
            throw new \Exception('No data rows found in the file. Please ensure the file is not empty and contains data below the header row.');
        }

        // First, validate all rows.
        $validator = Validator::make($rows->toArray(), [
            '*.item_code' => 'required|string|max:255',
            '*.item_name' => 'required|string|max:255',
            '*.area' => 'nullable|string|max:255',
            '*.category_2' => 'nullable|string|max:255',
            '*.category' => 'nullable|string|max:255',
            '*.brand' => 'nullable|string|max:255',
            '*.packaging_config' => 'nullable|string|max:255',
            '*.config' => 'nullable|integer',
            '*.uom' => 'nullable|string|max:255',
        ], [
            '*.item_code.required' => 'The "item_code" column is required for all rows.',
            '*.item_name.required' => 'The "item_name" column is required for all rows.',
        ]);

        // If validation fails, throw an exception with the first error message.
        if ($validator->fails()) {
            throw new \Exception($validator->errors()->first());
        }

        $createdCount = 0;
        // If validation passes, create the models.
        foreach ($rows as $row) {
            // Skip row if both item_code and item_name are missing, as an extra safeguard.
            if (empty($row['item_code']) && empty($row['item_name'])) {
                continue;
            }

            MonthEndCountTemplate::create([
                'item_code'        => $row['item_code'],
                'item_name'        => $row['item_name'],
                'area'             => $row['area'] ?? null,
                'category_2'       => $row['category_2'] ?? null,
                'category'         => $row['category'] ?? null,
                'brand'            => $row['brand'] ?? null,
                'packaging_config' => $row['packaging_config'] ?? null,
                'config'           => $row['config'] ?? null,
                'uom'              => $row['uom'] ?? null,
                'created_by'       => Auth::id(),
            ]);
            $createdCount++;
        }

        if ($createdCount === 0) {
            throw new \Exception('The file was processed, but no valid rows were found to import. Please check the file content.');
        }
    }
}