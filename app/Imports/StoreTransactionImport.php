<?php

namespace App\Imports;

use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use App\Models\StoreTransactionItem;
use App\Models\WIP;
use App\Traits\InventoryUsage;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class StoreTransactionImport implements ToModel, WithHeadingRow, WithStartRow
{
    use InventoryUsage;
    private $emptyRowCount = 0;
    private $maxEmptyRows = 5;
    private $rowNumber = 0;
    protected $skippedRows = [];

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
        $currentRawRow = $row;

        Log::info('Processing row ' . $this->rowNumber, [
            'raw_data' => $currentRawRow,
            'headers_found' => array_keys($currentRawRow)
        ]);

        foreach ($currentRawRow as $value) {
            if (is_string($value) && stripos($value, 'NOTHING FOLLOWS') !== false) {
                Log::info('Found "NOTHING FOLLOWS". Ending import.', [
                    'row_number' => $this->rowNumber
                ]);
                return null;
            }
        }

        if (is_string($currentRawRow['product_name']) && stripos($currentRawRow['product_name'], 'SUBTOTAL:') !== false) {
            return null;
        }

        if (empty($currentRawRow['product_id'])) {
            $this->skippedRows[] = [
                'row_number' => $this->rowNumber,
                'reason' => 'Product ID is missing or empty.',
                'data' => $currentRawRow
            ];
            $this->emptyRowCount++;
            if ($this->emptyRowCount >= $this->maxEmptyRows) {
                return null;
            }
            return null;
        }

        $this->emptyRowCount = 0;

        try {
            $branch = StoreBranch::where('location_code', $currentRawRow['branch'])->first();

            if (!$branch) {
                $this->skippedRows[] = [
                    'row_number' => $this->rowNumber,
                    'reason' => 'Branch not found: ' . $currentRawRow['branch'],
                    'data' => $currentRawRow
                ];
                Log::error('Branch not found', [
                    'location_code' => $currentRawRow['branch'],
                    'row_number' => $this->rowNumber
                ]);
                return null;
            } else {
                Log::info('Branch', [
                    'location_code' => $currentRawRow['branch'],
                    'branch' => $branch
                ]);
            }

            $excelPosCode = strtoupper(trim($currentRawRow['product_id']));
            $posMasterfile = POSMasterfile::whereRaw('UPPER(POSCode) = ?', [$excelPosCode])->first();

            if (!$posMasterfile) {
                $this->skippedRows[] = [
                    'row_number' => $this->rowNumber,
                    'reason' => 'POS Masterfile not found for Product ID (POSCode): ' . $currentRawRow['product_id'],
                    'data' => $currentRawRow
                ];
                Log::error('POS Masterfile not found for product_id (POSCode)', [
                    'product_id' => $currentRawRow['product_id'],
                    'excel_pos_code_processed' => $excelPosCode,
                    'row_number' => $this->rowNumber
                ]);
                return null;
            }

            // Create or update StoreTransaction
            $transaction = StoreTransaction::updateOrCreate([
                'store_branch_id' => $branch->id,
                'order_date' => $this->transformDate($currentRawRow['date']),
                'posted' => $currentRawRow['posted'],
                'tim_number' => $currentRawRow['tm'],
                'receipt_number' => $currentRawRow['receipt_no'],
            ]);

            // Create or update StoreTransactionItem, linking to POSMasterfile via product_id
            $storeTransactionItem = $transaction->store_transaction_items()->updateOrCreate(
                [
                    'product_id' => $posMasterfile->id,
                    'store_transaction_id' => $transaction->id,
                ],
                [
                    'base_quantity' => $currentRawRow['base_qty'],
                    'quantity' => $currentRawRow['qty'],
                    'price' => $currentRawRow['price'],
                    'discount' => $currentRawRow['discount'],
                    'line_total' => $currentRawRow['line_total'],
                    'net_total' => $currentRawRow['net_total'],
                ]
            );

            // --- INVENTORY DEDUCTION LOGIC ---
            Log::debug("StoreTransactionImport: Fetching BOM ingredients for POSCode: {$posMasterfile->POSCode} for StoreTransactionItem ID: {$storeTransactionItem->id}");
            $bomIngredients = POSMasterfileBOM::where('POSCode', $posMasterfile->POSCode)->get();
            Log::debug("StoreTransactionImport: Found {$bomIngredients->count()} BOM ingredients for POSCode: {$posMasterfile->POSCode}. Details: " . json_encode($bomIngredients->pluck('id', 'ItemCode')));


            if ($bomIngredients->isEmpty()) {
                $this->skippedRows[] = [
                    'row_number' => $this->rowNumber,
                    'reason' => "No Bill of Materials (BOM) entries found for POS Code: {$posMasterfile->POSCode}. Inventory not deducted for this item.",
                    'data' => $currentRawRow
                ];
                Log::warning("No Bill of Materials (BOM) entries found for POS Code: {$posMasterfile->POSCode}. Skipping inventory deduction for this item.");
                return $transaction; // Still return transaction, but skip inventory deduction
            }

            foreach ($bomIngredients as $ingredientBOM) {
                Log::debug("StoreTransactionImport: Processing BOM entry ID: {$ingredientBOM->id}, ItemCode: {$ingredientBOM->ItemCode}, ItemDescription: {$ingredientBOM->ItemDescription}, Assembly: {$ingredientBOM->Assembly}, BOMQty: {$ingredientBOM->BOMQty}, BOMUOM: {$ingredientBOM->BOMUOM}");

                $sapProduct = SAPMasterfile::where('ItemCode', $ingredientBOM->ItemCode)
                                            ->where(function ($query) use ($ingredientBOM) {
                                                $query->whereRaw('UPPER(BaseUOM) = ?', [strtoupper($ingredientBOM->BOMUOM)])
                                                      ->orWhereRaw('UPPER(AltUOM) = ?', [strtoupper($ingredientBOM->BOMUOM)]);
                                            })
                                            ->first();

                if (!$sapProduct) {
                    $this->skippedRows[] = [
                        'row_number' => $this->rowNumber,
                        'reason' => "SAP Masterfile entry not found for ItemCode: '{$ingredientBOM->ItemCode}' with UOM: '{$ingredientBOM->BOMUOM}' for POS item {$posMasterfile->POSDescription}. Inventory not deducted.",
                        'data' => $currentRawRow
                    ];
                    Log::error("StoreTransactionImport: SAP Masterfile entry not found for ItemCode: '{$ingredientBOM->ItemCode}' with UOM: '{$ingredientBOM->BOMUOM}' for POS item {$posMasterfile->POSDescription}. Skipping deduction for this ingredient.");
                    continue; // Skip this specific ingredient, but continue with others if any
                }
                Log::debug("StoreTransactionImport: SAP Product found for ItemCode: {$sapProduct->ItemCode}, SAP ID: {$sapProduct->id}, BaseUOM: {$sapProduct->BaseUOM}, AltUOM: {$sapProduct->AltUOM}");


                $productStock = ProductInventoryStock::with('sapMasterfile')
                    ->where('product_inventory_id', $sapProduct->id)
                    ->where('store_branch_id', $branch->id)
                    ->first();

                if (!$productStock) {
                    $this->skippedRows[] = [
                        'row_number' => $this->rowNumber,
                        'reason' => "Product inventory stock not found for SAP Item '{$sapProduct->ItemDescription}' (SAP ID: {$sapProduct->id}) in branch '{$branch->location_code}'. Inventory not deducted.",
                        'data' => $currentRawRow
                    ];
                    Log::error("StoreTransactionImport: Product inventory stock not found for SAP Item '{$sapProduct->ItemDescription}' (SAP ID: {$sapProduct->id}) in branch '{$branch->location_code}'. Skipping deduction for this ingredient.");
                    continue; // Skip this specific ingredient
                }
                Log::debug("StoreTransactionImport: Product Stock found for SAP ID: {$productStock->product_inventory_id}, Current Quantity: {$productStock->quantity}, Used: {$productStock->used}");


                // CRITICAL FIX: Ensure all quantities are treated as floats for precise calculation
                $bomQtyFloat = (float)$ingredientBOM->BOMQty;
                $transactionItemQuantityFloat = (float)$storeTransactionItem->quantity;

                $requiredQuantity = $bomQtyFloat * $transactionItemQuantityFloat;
                $stockOnHand = (float)$productStock->quantity - (float)$productStock->used;

                if ($requiredQuantity > $stockOnHand) {
                    $this->skippedRows[] = [
                        'row_number' => $this->rowNumber,
                        'reason' => "Insufficient inventory for '{$sapProduct->ItemDescription}'. Required: {$requiredQuantity}, Available: {$stockOnHand}. Inventory not deducted.",
                        'data' => $currentRawRow
                    ];
                    Log::error("StoreTransactionImport: Insufficient inventory for '{$sapProduct->ItemDescription}'. Required: {$requiredQuantity}, Available: {$stockOnHand}. Skipping deduction for this ingredient.");
                    continue; // Skip deduction due to insufficient stock
                }
                Log::debug("StoreTransactionImport: Required Quantity: {$requiredQuantity}, Stock On Hand: {$stockOnHand}. Proceeding with deduction.");


                $usedQuantity = $requiredQuantity;
                $productStock->update([
                    'used' => (float)$productStock->used + $usedQuantity // CRITICAL FIX: Ensure float addition
                ]);

                // Create a new ProductInventoryStockManager record for 'Action Out'
                ProductInventoryStockManager::create([
                    'product_inventory_id' => $sapProduct->id,
                    'store_branch_id' => $branch->id,
                    'cost_center_id' => null,
                    'quantity' => $usedQuantity, // This should now be the exact float value
                    'action' => 'out', // Explicitly set action as 'out'
                    'unit_cost' => (float)$ingredientBOM->UnitCost, // CRITICAL FIX: Ensure float
                    'total_cost' => (float)$ingredientBOM->UnitCost * $usedQuantity, // Calculate total cost with float
                    'transaction_date' => $transaction->order_date,
                    'remarks' => "Deducted from store transaction (Receipt No. {$transaction->receipt_number}) for POS item '{$posMasterfile->POSDescription}' ingredient '{$sapProduct->ItemDescription}' (BOM ID: {$ingredientBOM->id}, Assembly: {$ingredientBOM->Assembly})"
                ]);

                Log::info('Success processing BOM ingredient', [
                    'bom_id' => $ingredientBOM->id,
                    'sap_product_id' => $sapProduct->id,
                    'used_quantity' => $usedQuantity,
                    'transaction_id' => $transaction->id,
                    'item_id' => $storeTransactionItem->id,
                ]);
            }

            return $transaction;
        } catch (Exception $e) {
            $this->skippedRows[] = [
                'row_number' => $this->rowNumber,
                'reason' => 'Unhandled error during row processing: ' . $e->getMessage(),
                'data' => $currentRawRow
            ];
            Log::error('Error processing transaction row', [
                'row_number' => $this->rowNumber,
                'error' => $e->getMessage(),
                'raw_row' => $currentRawRow
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

    /**
     * Get the list of skipped rows.
     *
     * @return array
     */
    public function getSkippedRows(): array
    {
        return $this->skippedRows;
    }
}
