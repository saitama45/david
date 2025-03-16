<?php

namespace App\Imports;

use App\Models\Menu;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use App\Traits\InventoryUsage;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class StoreTransactionImport implements ToModel, WithStartRow, WithHeadingRow
{
    use InventoryUsage;
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
            } else {
                Log::info('Branch', [
                    'location_code' => $row['branch'],
                    'branch' => $branch
                ]);
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

            $ingredients?->map(function ($ingredient) use ($branch, $storeTransactionItem, $transaction, $row) {
                DB::beginTransaction();
                try {
                    $product = ProductInventoryStock::where('product_inventory_id',  $ingredient->product_inventory_id)->where('store_branch_id', $branch->id)->first();
                    $stockOnHand = $product->quantity - $product->used;

                    // if ($ingredient->quantity * $storeTransactionItem->quantity > $stockOnHand) {
                    //     return back()->withErrors([
                    //         "quantity" => "Quantity used can't be greater than stock on hand. (Stock on hand: $stockOnHand)"
                    //     ]);
                    // }

                    $product->used += $ingredient->quantity * $storeTransactionItem->quantity;

                    $product->update([
                        'used' => $ingredient->quantity * $storeTransactionItem->quantity
                    ]);
                    $data = [
                        'id' => $ingredient->product_inventory_id,
                        'store_branch_id' => $branch->id,
                        'cost_center_id' => null,
                        'quantity' => ($ingredient->quantity * $storeTransactionItem->quantity),
                        'transaction_date' => $transaction->order_date,
                        'remarks' => "Deducted from store transaction (Receipt No. {$transaction->receipt_number})"
                    ];
                    $this->handleInventoryUsage($data);

                    Log::info('Success', ['result' => $product]);
                } catch (Exception $e) {
                    Log::error('Failed', ['error' => $e->getMessage()]);
                    throw $e;
                }

                DB::commit();
            });

            DB::commit();

            return $transaction;
        } catch (Exception $e) {
            Log::error('Error processing transaction', [
                'row_number' => $this->rowNumber,
                'error' => $e->getMessage()
            ]);
            throw $e;
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
