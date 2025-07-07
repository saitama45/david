<?php

namespace App\Imports;

use App\Models\SAPMasterfile;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow; // Make sure this trait is imported
use Maatwebsite\Excel\Concerns\WithBatchInserts; // For performance with large datasets
use Maatwebsite\Excel\Concerns\WithChunkReading; // For performance with very large datasets


class SAPMasterfileImport implements ToModel, WithHeadingRow, WithBatchInserts, WithChunkReading //, WithValidation, SkipsOnError, SkipsOnFailure
{
    /**
    * @param array $row
    *
    * @return \Illuminate\Database\Eloquent\Model|null
    */
    public function model(array $row)
    {
        // Debugging tip: Uncomment the line below to see the exact keys Laravel-Excel generates.
        // It typically converts headers to snake_case.
        // For example: "Item No." -> "item_no", "Item Description" -> "item_description", "AltUom" -> "alt_uom"
        // dd($row);

        // Handle the 'is_active' field.
        // Since it's not present in your Excel columns, we'll assign a default value.
        // If you want to control this via the Excel file, you'd need to add a column for it
        // (e.g., 'Status' and map 'Active'/'Inactive' to 1/0).
        $is_active = 1; // Default to active (true)

        return new SAPMasterfile([
            'ItemNo' => $row['item_no'],
            'ItemDescription' => $row['item_description'],
            'AltQty' => (float) $row['altqty'] ?? 0,   // Change 'alt_qty' to 'altqty'
            'BaseQty' => (float) $row['baseqty'] ?? 0, // Change 'base_qty' to 'baseqty'
            'AltUOM' => $row['altuom'] ?? 'N/A',       // Change 'alt_uom' to 'altuom'
            'BaseUOM' => $row['baseuom'] ?? 'N/A',     // Change 'base_uom' to 'baseuom'
            'is_active' => $is_active,
            // The 'UoM Group', 'UgpCode', 'UgpName' columns are in your Excel,
            // but based on your SAPMasterfile model's fillable properties (from your controller's store/update methods),
            // these are not directly stored as attributes in the SAPMasterfile model itself.
            // If they are meant to be used for relationships or other logic, that would be handled here
            // or in a separate step after the initial import. For direct model mapping, they are ignored if not specified.
        ]);
    }

    /**
     * @return string|array
     */
    
    /**
     * Define the batch size for batch inserts.
     * This improves performance by inserting multiple rows at once.
     * @return int
     */
    public function batchSize(): int
    {
        return 200; // You can adjust this number based on your server's memory and database performance
    }

    /**
     * Define the chunk size for chunk reading.
     * This helps process very large files without running out of memory.
     * @return int
     */
    public function chunkSize(): int
    {
        return 1000; // You can adjust this number
    }


    /*
    // Optional: Implement WithValidation trait for row-level validation
    public function rules(): array
    {
        return [
            // Ensure these keys match the snake_cased headers from your Excel
            'item_no' => 'required|string|max:255', // Removed unique here because WithUpserts handles it
            'item_description' => 'required|string|max:255',
            'alt_qty' => 'nullable|numeric',
            'base_qty' => 'nullable|numeric',
            'alt_uom' => 'nullable|string|max:255',
            'base_uom' => 'required|string|max:255',
            // If you add an 'is_active' column to your Excel, add its rule here too, e.g.:
            // 'active_status' => 'required|in:Active,Inactive',
        ];
    }

    // Optional: Custom validation messages (requires WithValidation trait)
    // public function customValidationMessages()
    // {
    //     return [
    //         'item_no.required' => 'The Item No. field is required.',
    //         'item_description.required' => 'The Item Description field is required.',
    //     ];
    // }

    // Optional: If you want to skip rows that fail validation (requires SkipsOnFailure trait)
    // public function onFailure(Failure ...$failures)
    // {
    //     // Handle the failures here. You could log them, collect them,
    //     // or send notifications.
    //     foreach ($failures as $failure) {
    //         \Log::warning('Import validation failed: ' . $failure->row() . ' - ' . json_encode($failure->errors()));
    //     }
    // }
    */
}