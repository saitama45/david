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
    private $processedReceipts = [];

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

        // CRITICAL FIX: Check if 'product_name' key exists before accessing it
        if (isset($currentRawRow['product_name']) && is_string($currentRawRow['product_name']) && stripos($currentRawRow['product_name'], 'SUBTOTAL:') !== false) {
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

            $receiptNumber = $currentRawRow['receipt_no'];
            $receiptKey = $branch->id . '-' . $receiptNumber;

            // If we have not processed this receipt in the current import session
            if (!in_array($receiptKey, $this->processedReceipts)) {
                // Check if a transaction with this receipt number and branch already exists in the database
                $existingTransaction = StoreTransaction::where('store_branch_id', $branch->id)
                    ->where('receipt_number', $receiptNumber)
                    ->exists();

                if ($existingTransaction) {
                    $this->skippedRows[] = [
                        'row_number' => $this->rowNumber,
                        'reason' => "Skipped: Transaction with Receipt No. '{$receiptNumber}' for Branch '{$currentRawRow['branch']}' already exists in the database.",
                        'data' => $currentRawRow,
                    ];
                    return null; // Skip this row completely
                }

                // Mark this receipt as processed for the current import session
                $this->processedReceipts[] = $receiptKey;
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
        Log::debug('TransformDate: Initial value and type', ['value' => $value, 'type' => gettype($value)]);

        try {
            if (empty($value)) {
                Log::warning('TransformDate: Date value is empty');
                throw new Exception('Date value is empty');
            }

            // If the value is already a Carbon instance (from Maatwebsite\Excel), return it directly
            if ($value instanceof Carbon) {
                Log::debug('TransformDate: Value is already a Carbon instance', ['original' => $value->format('Y-m-d')]);
                return $value->format('Y-m-d');
            }

            // Attempt to parse as MM/DD/YYYY first, as per user's requirement
            if (is_string($value)) {
                try {
                    $date = Carbon::createFromFormat('m/d/Y', $value);
                    if ($date !== false) {
                        Log::debug('TransformDate: Parsed as MM/DD/YYYY', ['original' => $value, 'parsed' => $date->format('Y-m-d')]);
                        return $date->format('Y-m-d');
                    }
                } catch (\InvalidArgumentException $e) {
                    // This catch block will handle cases where createFromFormat fails to parse
                    Log::debug('TransformDate: MM/DD/YYYY parsing failed (InvalidArgumentException)', ['value' => $value, 'error' => $e->getMessage()]);
                }
            }

            // If not a string or if m/d/Y parsing failed, try numeric (Excel serial)
            if (is_numeric($value)) {
                // Excel's epoch is 1900-01-01, but it incorrectly treats 1900 as a leap year.
                // So, for dates after Feb 28, 1900, we subtract 2 days.
                // For dates up to Feb 28, 1900, we subtract 1 day.
                // For simplicity, assuming dates are well past 1900.
                $excelDate = Carbon::createFromDate(1900, 1, 1)->addDays((int)$value - 2);
                Log::debug('TransformDate: Parsed as Excel numeric serial', ['original' => $value, 'parsed' => $excelDate->format('Y-m-d')]);
                return $excelDate->format('Y-m-d');
            }

            // Fallback to general parsing if m/d/Y and numeric failed
            if (is_string($value)) {
                try {
                    $date = Carbon::parse($value);
                    Log::debug('TransformDate: Parsed using general Carbon::parse', ['original' => $value, 'parsed' => $date->format('Y-m-d')]);
                    return $date->format('Y-m-d');
                } catch (\Exception $e) {
                    Log::error('TransformDate: General Carbon::parse failed', ['value' => $value, 'error' => $e->getMessage()]);
                }
            }

            Log::error('TransformDate: No valid date format found');
            throw new Exception('Invalid date format');
        } catch (Exception $e) {
            Log::error('Date transformation failed in catch block', [
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
