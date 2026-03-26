<?php

namespace App\Imports;

use App\Models\SalesBudget;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Log;

class SalesBudgetImport implements ToModel, WithHeadingRow
{
    protected $type;
    protected $year;
    protected $messages = [];
    protected $skippedCount = 0;
    protected $updatedCount = 0;
    protected $insertedCount = 0;

    public function __construct(string $type, int $year)
    {
        $this->type = $type;
        $this->year = $year;
        Log::info('SalesBudgetImport initialized for type: ' . $type . ' year: ' . $year);
    }

    protected $keysLogged = false;

    public function model(array $row)
    {
        if (!$this->keysLogged) {
            Log::info('SalesBudgetImport row keys: ' . implode(', ', array_keys($row)));
            $this->keysLogged = true;
        }
        Log::info('Processing row in model(): ' . json_encode($row));
        $months = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

        // The heading row transforms spaces to underscores, e.g., 'store_code'
        if (empty($row['store_code'])) {
            Log::info('Skipping row because store_code is empty.');
            return null;
        }

        $storeCode = (string)$row['store_code'];
        $storeName = $row['store_name'] ?? $storeCode;

        $existingRecord = SalesBudget::where('type', $this->type)
            ->where('year', $this->year)
            ->where('store_code', $storeCode)
            ->first();

        $newData = [];
        foreach ($months as $month) {
            $newData[$month] = $this->parseAmount($row[$month] ?? null);
        }
        
        $newData['store_name'] = $storeName;

        if ($existingRecord) {
            $differences = [];
            $isIdentical = true;

            foreach ($months as $month) {
                $oldVal = (double) $existingRecord->$month;
                $newVal = (double) $newData[$month];

                if (abs($oldVal - $newVal) > 0.001) { // Floating point comparison safety
                    $isIdentical = false;
                    $differences[] = ucfirst($month) . " (From: " . number_format($oldVal, 2) . " To: " . number_format($newVal, 2) . ")";
                    $existingRecord->$month = $newVal;
                }
            }

            if ($existingRecord->store_name !== $newData['store_name']) {
                $existingRecord->store_name = $newData['store_name'];
                $isIdentical = false;
            }

            if ($isIdentical) {
                $this->skippedCount++;
                $this->messages[] = [
                    'type' => 'skipped',
                    'message' => "Skipped: {$storeName} ({$storeCode}) for Year {$this->year} already exists with the same values."
                ];
                Log::info('Skipped: ' . $storeCode);
            } else {
                $existingRecord->save();
                $this->updatedCount++;
                $diffString = implode(', ', $differences);
                $this->messages[] = [
                    'type' => 'updated',
                    'message' => "Updated: {$storeName} ({$storeCode}) Year {$this->year} values changed: {$diffString}."
                ];
                Log::info('Updated: ' . $storeCode);
            }
        } else {
            SalesBudget::create(array_merge([
                'type' => $this->type,
                'year' => $this->year,
                'store_code' => $storeCode,
            ], $newData));
            
            $this->insertedCount++;
            $this->messages[] = [
                'type' => 'inserted',
                'message' => "Inserted: New record for {$storeName} ({$storeCode}) Year {$this->year}."
            ];
            Log::info('Inserted: ' . $storeCode);
        }

        return null; // Return null to prevent default insertion
    }

    private function parseAmount($value)
    {
        if ($value === null || $value === '') {
            return 0;
        }
        
        // Remove commas or other formatting if present
        $value = str_replace(',', '', (string)$value);
        return (double) $value;
    }

    public function getSummaryMessages(): array
    {
        return $this->messages;
    }

    public function getSummaryCounts(): array
    {
        return [
            'inserted' => $this->insertedCount,
            'updated' => $this->updatedCount,
            'skipped' => $this->skippedCount,
        ];
    }
}
