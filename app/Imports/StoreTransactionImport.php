<?php

namespace App\Imports;

use App\Models\Menu;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class StoreTransactionImport implements ToModel, WithStartRow, WithHeadingRow
{
    private $emptyRowCount = 0;
    private $maxEmptyRows = 5;
    private $rowNumber = 0;

    public function startRow(): int
    {
        return 7; // Increment by 1 to skip the header row
    }

    public function headingRow(): int
    {
        return 6;
    }

    public function model(array $row)
    {
        $this->rowNumber++;
        Log::info('Processing row ' . $this->rowNumber, [
            'raw_data' => $row,
            'headers_found' => array_keys($row)
        ]);

        foreach ($row as $value) {
            if (is_string($value) && stripos($value, 'NOTHING FOLLOWS') !== false) {
                Log::info('Found "NOTHING FOLLOWS". Ending import.', [
                    'row_number' => $this->rowNumber
                ]);
                return null;
            }
        }

        if (is_string($row['product_name']) && stripos($row['product_name'], 'SUBTOTAL:') !== false) {
            return null;
        }

        if (empty($row['product_id'])) {
            $this->emptyRowCount++;
            if ($this->emptyRowCount >= $this->maxEmptyRows) {
                return null;
            }
            return null;
        }

        $this->emptyRowCount = 0;

        try {
            $branch = StoreBranch::where('location_code', 'UTC')->first();

            if (!$branch) {
                Log::error('Branch not found', [
                    'location_code' => 'UTC',
                    'row_number' => $this->rowNumber
                ]);
                return null;
            }

            $menu = Menu::firstOrNew([
                'product_id' => $row['product_id']
            ], [
                'category_id' => 1,
                'name' => $row['product_name'],
                'price' => $row['price']
            ]);

            if (!$menu->exists) {
                $menu->save();
            }

            $transaction = StoreTransaction::updateOrCreate([
                'store_branch_id' => $branch->id,
                'order_date' => $this->transformDate($row['date']),
                'posted' => $row['posted'],
                'tim_number' => $row['tm'],
                'receipt_number' => $row['receipt_no'],
            ]);

            $transaction->store_transaction_items()->updateOrCreate([
                'product_id' => $menu->id,
                'base_quantity' => $row['base_qty'],
                'quantity' => $row['qty'],
                'price' => $row['price'],
                'discount' => $row['discount'],
                'line_total' => $row['line_total'],
                'net_total' => $row['net_total'],
            ]);

            return $transaction;
        } catch (Exception $e) {
            Log::error('Error processing transaction', [
                'row_number' => $this->rowNumber,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function transformDate($value)
    {
        try {
            if (empty($value)) {
                throw new Exception('Date value is empty');
            }

            if (is_numeric($value)) {
                return Carbon::createFromDate(1900, 1, 1)
                    ->addDays((int)$value - 2)
                    ->format('Y-m-d');
            }

            if (is_string($value)) {
                return Carbon::parse($value)->format('Y-m-d');
            }

            throw new Exception('Invalid date format');
        } catch (Exception $e) {
            Log::error('Date transformation failed', [
                'value' => $value,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
