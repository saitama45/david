<?php

namespace App\Imports;

use App\Models\MonthEndCountTemplate;
use App\Models\SAPMasterfile;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterImport;

class MonthEndCountTemplatesImport implements ToCollection, WithHeadingRow, WithStartRow, SkipsEmptyRows, WithEvents
{
    private int $createdCount = 0;
    private int $updatedCount = 0;
    private array $skippedRows = [];
    private bool $collectionCalled = false; // New flag

    /**
     * Set of keys (item_code|bulk_uom|loose_uom) present in the uploaded file.
     * Used after import to tag missing items as inactive and present items as active.
     */
    private array $presentKeys = [];
    private int $reactivatedCount = 0;
    private int $deactivatedCount = 0;

    /**
     * Column mapping to handle user-friendly Excel column names
     * Maps user column names to expected system column names
     */
    private array $columnMapping = [
        'Item Code' => 'item_code',
        'Item Name' => 'item_name',
        'Category 1' => 'category_1',
        'Category1' => 'category_1',
        'category_1' => 'category_1',
        'Area' => 'area',
        'Category 2' => 'category_2',
        'Category2' => 'category_2',
        'category_2' => 'category_2',
        'Packaging' => 'packaging',
        'packaging' => 'packaging',
        'Conversion' => 'conversion',
        'conversion' => 'conversion',
        'Bulk UOM' => 'bulk_uom',
        'BulkUOM' => 'bulk_uom',
        'Bulk_UOM' => 'bulk_uom',
        'bulk_uom' => 'bulk_uom',
        'Loose UOM' => 'loose_uom',
        'LooseUOM' => 'loose_uom',
        'Loose_UOM' => 'loose_uom',
        'loose_uom' => 'loose_uom',
    ];

    public function startRow(): int
    {
        return 2; // Data starts from row 2
    }

    public function headingRow(): int
    {
        return 1; // Header is on row 1
    }

    /**
     * Normalize column names before validation
     */
    public function prepareForValidation(array $row, int $index)
    {
        // Check if row is completely empty (all values are null, empty strings, or just whitespace)
        // Using a more robust check for various whitespace characters including non-breaking spaces
        $isEmpty = true;
        foreach ($row as $value) {
            if (!is_null($value)) {
                $strValue = (string)$value;
                // Replace non-breaking spaces and other invisible characters with regular space, then trim
                $cleanValue = trim(preg_replace('/[\x00-\x1F\x7F\xA0]/u', ' ', $strValue));
                
                if ($cleanValue !== '') {
                    $isEmpty = false;
                    break;
                }
            }
        }

        // If row is completely empty, return it as-is to be filtered out in collection()
        if ($isEmpty) {
            Log::info('Import: Skipping empty row during validation', ['index' => $index, 'row' => $row]);
            return $row;
        }

        $normalizedRow = [];

        foreach ($row as $columnName => $value) {
            // Remove extra spaces and normalize column name
            $cleanColumnName = trim($columnName);

            // Map user-friendly column names to system names
            $mappedColumnName = $this->columnMapping[$cleanColumnName] ?? $cleanColumnName;

            $normalizedRow[$mappedColumnName] = $value;
        }

        Log::info('Import: Row normalized', [
            'index' => $index,
            'original' => $row,
            'normalized' => $normalizedRow
        ]);

        return $normalizedRow;
    }

    /**
    * @param Collection $rows
    */
    public function collection(Collection $rows)
    {
        $this->collectionCalled = true;
        
        Log::info('Import: Collection method started.', ['initial_rows_count' => $rows->count()]);

        $rules = [
            'item_code' => 'required|string|max:255',
            'item_name' => 'required|string|max:255',
            'conversion' => 'nullable|numeric',
            'area' => 'nullable|string|max:255',
            'category_2' => 'nullable|string|max:255',
            'category_1' => 'nullable|string|max:255',
            'packaging' => 'nullable|string|max:255',
            'bulk_uom' => 'nullable|string|max:255',
            'loose_uom' => 'nullable|string|max:255',
        ];

        $messages = [
            'item_code.required' => 'The "item_code" column is required.',
            'item_name.required' => 'The "item_name" column is required.',
            'conversion.numeric' => 'The "conversion" column must be a number.',
        ];

        $processedRowsCount = 0;
        $skippedByEmptyCheckCount = 0;

        foreach ($rows as $rowIndex => $row) {
            $originalRowNumber = $this->startRow() + $rowIndex;
            $rowData = $row->toArray();
            
            Log::debug('Import: Processing row', ['original_row_number' => $originalRowNumber, 'raw_data' => $rowData]);

            // Check if row is effectively empty (all values are null, empty strings, or just whitespace)
            // Use the same robust empty check as in prepareForValidation
            $isEmpty = true;
            foreach ($rowData as $value) {
                if (!is_null($value)) {
                    $strValue = (string)$value;
                    $cleanValue = trim(preg_replace('/[\x00-\x1F\x7F\xA0]/u', ' ', $strValue));
                    if ($cleanValue !== '') {
                        $isEmpty = false;
                        break;
                    }
                }
            }

            if ($isEmpty) {
                $skippedByEmptyCheckCount++;
                Log::info('Import: Row skipped by empty check', ['original_row_number' => $originalRowNumber]);
                continue;
            }

            // Check if essential columns are missing (Item Code AND Item Name)
            // If both are missing, treat it as a trailing empty/garbage row and skip without error
            $itemCode = (string) Arr::get($rowData, 'item_code');
            $itemName = (string) Arr::get($rowData, 'item_name');
            
            if (trim($itemCode) === '' && trim($itemName) === '') {
                $skippedByEmptyCheckCount++;
                Log::info('Import: Row skipped because both Item Code and Item Name are empty', ['original_row_number' => $originalRowNumber]);
                continue;
            }

            $processedRowsCount++;

            $validator = Validator::make($rowData, $rules, $messages);

            if ($validator->fails()) {
                $this->skippedRows[] = [
                    'row_number' => $originalRowNumber,
                    'item_code' => Arr::get($rowData, 'item_code', 'N/A'),
                    'uom' => Arr::get($rowData, 'bulk_uom', 'N/A'),
                    'reason' => 'Validation failed: ' . implode(', ', $validator->errors()->all()),
                ];
                Log::warning('Import: Row validation failed', ['original_row_number' => $originalRowNumber, 'errors' => $validator->errors()->all(), 'data' => $rowData]);
                continue;
            }
            
            $bulkUom = Arr::get($row, 'bulk_uom');

            // Validate against SAPMasterfile
            $sapExists = SAPMasterfile::where('ItemCode', $itemCode)
                ->where('AltUOM', $bulkUom)
                ->exists();

            if (!$sapExists) {
                $this->skippedRows[] = [
                    'row_number' => $originalRowNumber,
                    'item_code' => $itemCode,
                    'uom' => $bulkUom,
                    'reason' => "Item Code '$itemCode' and Bulk UOM '$bulkUom' do not exist in SAP Masterfile.",
                ];
                Log::info('Import: Row skipped due to missing SAP Masterfile record', ['original_row_number' => $originalRowNumber, 'item_code' => $itemCode, 'uom' => $bulkUom]);
                continue;
            }

            // This row is valid and present in the uploaded file, so it must be
            // tagged active during the post-import sync (item_code + bulk uom + loose uom).
            $looseUom = Arr::get($row, 'loose_uom');
            $this->presentKeys[$this->makeKey($itemCode, $bulkUom, $looseUom)] = true;

            $conversionValue = Arr::get($row, 'conversion');
            $parsedConversion = null;
            if ($conversionValue !== null) {
                if (preg_match('/(\d+(\.\d+)?)/', (string)$conversionValue, $matches)) {
                    $parsedConversion = (float) $matches[1];
                }
            }

            $template = MonthEndCountTemplate::where('item_code', $itemCode)
                ->where('uom', $bulkUom)
                ->first();

            $dataToCompare = [
                'item_name'        => $itemName,
                'area'             => Arr::get($row, 'area'),
                'category_2'       => Arr::get($row, 'category_2'),
                'category'         => Arr::get($row, 'category_1'),
                'packaging_config' => Arr::get($row, 'packaging'),
                'config'           => $parsedConversion,
                'loose_uom'        => Arr::get($row, 'loose_uom'),
            ];

            if ($template) {
                $isChanged = false;
                foreach ($dataToCompare as $key => $value) {
                    if (isset($template->{$key}) && $template->{$key} != $value) {
                        $isChanged = true;
                        break;
                    } elseif (!isset($template->{$key}) && $value !== null) {
                        $isChanged = true;
                        break;
                    }
                }

                if ($isChanged) {
                    $template->update(array_merge($dataToCompare, ['updated_by' => Auth::id()]));
                    $this->updatedCount++;
                    Log::info('Import: Template updated', ['original_row_number' => $originalRowNumber, 'item_code' => $itemCode]);
                } else {
                    $this->skippedRows[] = [
                        'row_number' => $originalRowNumber,
                        'item_code' => $itemCode,
                        'bulk_uom' => $bulkUom,
                        'reason' => 'Item code with the same Bulk UOM already exists and has no changes.',
                    ];
                    Log::info('Import: Row skipped due to no changes', ['original_row_number' => $originalRowNumber, 'item_code' => $itemCode]);
                }
            } else {
                MonthEndCountTemplate::create(array_merge($dataToCompare, [
                    'item_code'  => $itemCode,
                    'uom'        => $bulkUom,
                    'created_by' => Auth::id(),
                ]));
                $this->createdCount++;
                Log::info('Import: New template created', ['original_row_number' => $originalRowNumber, 'item_code' => $itemCode]);
            }
        }
        Log::info('Import: Collection method finished.', [
            'total_rows_from_excel' => $rows->count(),
            'rows_skipped_by_empty_check' => $skippedByEmptyCheckCount,
            'rows_processed_for_validation' => $processedRowsCount,
            'final_skipped_items_count' => count($this->skippedRows),
            'final_created_count' => $this->createdCount,
            'final_updated_count' => $this->updatedCount,
        ]);
    }

    /**
     * After the whole file is imported, reconcile active/inactive status:
     *  - items present in the uploaded file  -> active
     *  - items missing from the uploaded file -> inactive
     */
    public function registerEvents(): array
    {
        return [
            AfterImport::class => function (AfterImport $event) {
                $this->syncActiveStatus();
            },
        ];
    }

    /**
     * Build the comparison key used to decide presence in the upload.
     * Matches by Item Code + Bulk UOM + Loose UOM (case-insensitive, trimmed).
     */
    private function makeKey($itemCode, $bulkUom, $looseUom): string
    {
        return strtolower(trim((string) $itemCode)) . '|'
            . strtolower(trim((string) $bulkUom)) . '|'
            . strtolower(trim((string) $looseUom));
    }

    /**
     * Tag templates active/inactive based on what was present in the upload.
     * Scoped to the current entity automatically via the BelongsToEntity global scope.
     */
    private function syncActiveStatus(): void
    {
        // Safety guard: if nothing valid was imported, leave existing data untouched
        // so an empty or invalid file does not deactivate the whole list.
        if (empty($this->presentKeys)) {
            Log::info('Import: No valid rows processed; skipping active/inactive sync.');
            return;
        }

        $idsToActivate = [];
        $idsToDeactivate = [];

        MonthEndCountTemplate::query()
            ->select('id', 'item_code', 'uom', 'loose_uom', 'is_active')
            ->chunkById(1000, function ($templates) use (&$idsToActivate, &$idsToDeactivate) {
                foreach ($templates as $template) {
                    $key = $this->makeKey($template->item_code, $template->uom, $template->loose_uom);
                    $present = isset($this->presentKeys[$key]);

                    if ($present && !$template->is_active) {
                        $idsToActivate[] = $template->id;
                    } elseif (!$present && $template->is_active) {
                        $idsToDeactivate[] = $template->id;
                    }
                }
            });

        foreach (array_chunk($idsToActivate, 1000) as $chunk) {
            MonthEndCountTemplate::whereIn('id', $chunk)->update(['is_active' => 1, 'updated_by' => Auth::id(), 'updated_at' => now()]);
        }

        foreach (array_chunk($idsToDeactivate, 1000) as $chunk) {
            MonthEndCountTemplate::whereIn('id', $chunk)->update(['is_active' => 0, 'updated_by' => Auth::id(), 'updated_at' => now()]);
        }

        $this->reactivatedCount = count($idsToActivate);
        $this->deactivatedCount = count($idsToDeactivate);

        Log::info('Import: Active/inactive sync complete.', [
            'reactivated' => $this->reactivatedCount,
            'deactivated' => $this->deactivatedCount,
        ]);
    }

    /**
     * Convert system column name back to user-friendly format for error messages
     */
    private function getUserFriendlyColumnName(string $columnName): string
    {
        $reverseMapping = [
            'item_code' => 'Item Code',
            'item_name' => 'Item Name',
            'category_1' => 'Category 1',
            'area' => 'Area',
            'category_2' => 'Category 2',
            'packaging' => 'Packaging',
            'conversion' => 'Conversion',
            'bulk_uom' => 'Bulk UOM',
            'loose_uom' => 'Loose UOM',
        ];

        return $reverseMapping[$columnName] ?? $columnName;
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

    public function getCollectionCalled(): bool
    {
        return $this->collectionCalled;
    }

    public function getReactivatedCount(): int
    {
        return $this->reactivatedCount;
    }

    public function getDeactivatedCount(): int
    {
        return $this->deactivatedCount;
    }
}