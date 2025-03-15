<?php

namespace App\Imports;

use App\Models\Menu;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        return 7;
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
            $branch = StoreBranch::where('location_code', $row['branch'])->first();

            if (!$branch) {
                Log::error('Branch not found', [
                    'location_code' => $row['branch'],
                    'row_number' => $this->rowNumber
                ]);
                return null;
            }

            DB::beginTransaction();

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

            $storeTransactionItem = $transaction->store_transaction_items()->updateOrCreate([
                'product_id' => $row['product_id'],
                'base_quantity' => $row['base_qty'],
                'quantity' => $row['qty'],
                'price' => $row['price'],
                'discount' => $row['discount'],
                'line_total' => $row['line_total'],
                'net_total' => $row['net_total'],
            ]);

            $ingredients = $storeTransactionItem->menu->menu_ingredients;

            $ingredients?->map(function ($ingredient) use ($branch, $storeTransactionItem, $transaction) {
                $ProductInventoryStock = ProductInventoryStock::where('product_inventory_id', $ingredient->product_inventory_id)
                    ->where('store_branch_id', $branch->id)
                    ->increment('used', $ingredient->quantity * $storeTransactionItem->quantity);



                $ProductInventoryStockManager = ProductInventoryStockManager::create([
                    'product_inventory_id' => $ingredient->product_inventory_id,
                    'store_branch_id' => $branch->id,
                    'cost_center_id' => null,
                    'quantity' => - ($ingredient->quantity * $storeTransactionItem->quantity),
                    'action' => 'deduct',
                    'remarks' => "Deducted from store transaction (Receipt No. {$transaction->receipt_number})"
                ]);

                Log::info('Processing row ' . $this->rowNumber, [
                    'product_inventory_stock' => $ProductInventoryStock,
                    'product_inventory_stock_manager' => $ProductInventoryStockManager
                ]);
            });

            DB::commit();

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
