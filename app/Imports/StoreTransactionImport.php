<?php

namespace App\Imports;

use App\Jobs\ProcessStoreTransactionJob;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class StoreTransactionImport implements ToArray, WithStartRow, WithHeadingRow, WithChunkReading
{
    private $emptyRowCount = 0;
    private $maxEmptyRows = 5;
    private $rowNumber = 0;
    private $batchSize = 100;
    private $requiredHeaders = ['product_id', 'product_name', 'branch', 'date', 'posted', 'tm', 'receipt_no', 'base_qty', 'qty', 'price', 'discount', 'line_total', 'net_total'];

    /**
     * Define the starting row for data
     * 
     * @return int
     */
    public function startRow(): int
    {
        return 7;
    }

    /**
     * Define the heading row
     * 
     * @return int
     */
    public function headingRow(): int
    {
        return 6;
    }

    /**
     * Define the chunk size for processing
     * 
     * @return int
     */
    public function chunkSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Process the array of rows
     * 
     * @param array $rows
     * @return void
     */
    public function array(array $rows)
    {
        Log::info('Processing chunk of data', [
            'chunk_size' => count($rows)
        ]);

        // Check if this is the first chunk and validate headers
        if ($this->rowNumber == 0 && !empty($rows)) {
            $firstRow = reset($rows);
            $missingHeaders = array_diff($this->requiredHeaders, array_keys($firstRow));

            if (!empty($missingHeaders)) {
                Log::error('Missing required headers in import file', [
                    'missing_headers' => $missingHeaders
                ]);
                throw new \Exception('Import file is missing required headers: ' . implode(', ', $missingHeaders));
            }
        }

        foreach ($rows as $row) {
            $this->rowNumber++;

            // Log the raw row data for debugging
            Log::debug('Processing row', [
                'row_number' => $this->rowNumber,
                'row_data' => $row
            ]);

            // Check for "NOTHING FOLLOWS" to end the import
            $endImport = false;
            foreach ($row as $value) {
                if (is_string($value) && stripos($value, 'NOTHING FOLLOWS') !== false) {
                    Log::info('Found "NOTHING FOLLOWS". Ending import.', [
                        'row_number' => $this->rowNumber
                    ]);
                    $endImport = true;
                    break;
                }
            }

            if ($endImport) {
                break;
            }

            // Skip subtotal rows
            if (isset($row['product_name']) && is_string($row['product_name']) && stripos($row['product_name'], 'SUBTOTAL:') !== false) {
                continue;
            }

            // Handle empty rows
            if (!isset($row['product_id']) || empty($row['product_id'])) {
                $this->emptyRowCount++;
                if ($this->emptyRowCount >= $this->maxEmptyRows) {
                    Log::info('Maximum empty rows reached. Ending import.', [
                        'row_number' => $this->rowNumber
                    ]);
                    break;
                }
                continue;
            }

            // Reset empty row counter
            $this->emptyRowCount = 0;

            // Dispatch a job to process this row
            ProcessStoreTransactionJob::dispatch($row, $this->rowNumber);

            Log::info('Job dispatched for row ' . $this->rowNumber);
        }
    }
}
