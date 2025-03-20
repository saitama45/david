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
            $errors = [];

            $ingredients?->each(function ($ingredient) use ($branch, $storeTransactionItem, $transaction, $row, &$errors) {
                try {
                    $product = ProductInventoryStock::with('product')
                        ->where('product_inventory_id', $ingredient->product_inventory_id)
                        ->where('store_branch_id', $branch->id)
                        ->first();

                    if (!$product) {
                        $errors[] = "Product inventory not found for branch: {$branch->location_code}";
                        return false; 
                    }

                    $stockOnHand = $product->quantity - $product->used;

                    if ($ingredient->quantity * $storeTransactionItem->quantity > $stockOnHand) {
                        $requiredQuantity = $ingredient->quantity * $storeTransactionItem->quantity;
                        $errors[] = "Insufficient inventory for '{$product->product->name}'. Required: {$requiredQuantity}, Available: {$stockOnHand}.";
                        return false; 
                    }

                    $usedQuantity = $ingredient->quantity * $storeTransactionItem->quantity;
                    $product->update([
                        'used' => $product->used + $usedQuantity
                    ]);

                    $data = [
                        'id' => $ingredient->product_inventory_id,
                        'store_branch_id' => $branch->id,
                        'cost_center_id' => null,
                        'quantity' => $usedQuantity,
                        'transaction_date' => $transaction->order_date,
                        'remarks' => "Deducted from store transaction (Receipt No. {$transaction->receipt_number})"
                    ];

                    $this->handleInventoryUsage($data);

                    Log::info('Success processing ingredient', [
                        'product_id' => $product->product_inventory_id,
                        'used_quantity' => $usedQuantity
                    ]);
                } catch (Exception $e) {
                    Log::error('Failed to process ingredient', ['error' => $e->getMessage()]);
                    $errors[] = "Error processing ingredient: " . $e->getMessage();
                    return false; 
                }
            });

            if (!empty($errors)) {
                DB::rollBack();
                Log::error('Errors during ingredient processing', [
                    'row_number' => $this->rowNumber,
                    'errors' => $errors
                ]);
                throw new Exception(implode("\n", $errors));
            }

            DB::commit();
            return $transaction;
        } catch (Exception $e) {
            Log::error('Error processing transaction', [
                'row_number' => $this->rowNumber,
                'error' => $e->getMessage(),
                'transaction_level' => DB::transactionLevel()
            ]);

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

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
