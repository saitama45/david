<?php

namespace App\Imports;

use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\ProductInventoryStockManager;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use App\Traits\InventoryUsage;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class StoreTransactionImport implements ToCollection, WithHeadingRow, WithStartRow
{
    use InventoryUsage;

    private $rowNumber = 0;
    protected $skippedRows = [];
    protected $createdCount = 0;
    protected $processedReceipts = [];

    public function startRow(): int
    {
        return 7;
    }

    public function headingRow(): int
    {
        return 6;
    }

    public function collection(Collection $rows)
    {
        // Pre-process rows to add row numbers and filter invalid ones
        $validRows = [];
        $this->rowNumber = $this->startRow() - 1; // Adjust for header

        foreach ($rows as $row) {
            $this->rowNumber++;
            
            // Check for "NOTHING FOLLOWS"
            foreach ($row as $value) {
                if (is_string($value) && stripos($value, 'NOTHING FOLLOWS') !== false) {
                    Log::info('Found "NOTHING FOLLOWS". Ending import.', ['row_number' => $this->rowNumber]);
                    break 2; // Stop processing entirely
                }
            }

            // Check for SUBTOTAL
            if (isset($row['product_name']) && is_string($row['product_name']) && stripos($row['product_name'], 'SUBTOTAL:') !== false) {
                continue;
            }

            // Skip if essential data is missing
            if (empty($row['product_id'])) {
                continue;
            }

            // Attach the original row number for tracking
            $row['__row_number'] = $this->rowNumber;
            $validRows[] = $row;
        }

        // GROUPING FIX: Trim whitespace from branch and receipt_no to ensure "11111" and "11111 " are grouped together
        $groupedByReceipt = collect($validRows)->groupBy(function ($item) {
            $branch = isset($item['branch']) ? trim($item['branch']) : '';
            $receipt = isset($item['receipt_no']) ? trim($item['receipt_no']) : '';
            return $branch . '|' . $receipt;
        });

        foreach ($groupedByReceipt as $key => $receiptRows) {
            $this->processReceiptGroup($receiptRows);
        }
    }

    private function processReceiptGroup($receiptRows)
    {
        $firstRow = $receiptRows->first();
        $branchCode = isset($firstRow['branch']) ? trim($firstRow['branch']) : '';
        $receiptNumber = isset($firstRow['receipt_no']) ? trim($firstRow['receipt_no']) : '';
        $date = $firstRow['date'];
        $posted = $firstRow['posted'];
        $tm = $firstRow['tm'];
        
        // Use the row number of the first item for logging generic errors for the receipt
        $mainRowNumber = $firstRow['__row_number'];

        try {
            // 1. Validate Branch
            $branch = StoreBranch::where('location_code', $branchCode)->first();
            if (!$branch) {
                $this->addSkippedGroup($receiptRows, "Branch not found: $branchCode");
                return;
            }

            // 2. Check if Transaction Already Exists
            $existingTransaction = StoreTransaction::where('store_branch_id', $branch->id)
                ->where('receipt_number', $receiptNumber)
                ->exists();

            if ($existingTransaction) {
                $this->addSkippedGroup($receiptRows, "Skipped: Transaction with Receipt No. '{$receiptNumber}' for Branch '{$branchCode}' already exists.");
                return;
            }

            // 3. Aggregate Items within Receipt (Sum Qty for same Product ID)
            $aggregatedItems = [];
            foreach ($receiptRows as $row) {
                $productId = strtoupper(trim($row['product_id']));
                
                if (!isset($aggregatedItems[$productId])) {
                    $aggregatedItems[$productId] = [
                        'product_id' => $productId,
                        'product_name' => $row['product_name'],
                        'uom' => $row['uom'] ?? null, // Handle missing UOM key
                        'qty' => 0,
                        'base_qty' => 0, // Assuming we sum this too
                        'price' => $row['price'], // Keeping first price
                        'discount' => 0,
                        'line_total' => 0,
                        'net_total' => 0,
                        'rows' => [], // Keep track of original rows
                        '__row_number' => $row['__row_number'],
                        'date' => $row['date'],
                        'branch' => $row['branch']
                    ];
                }

                $aggregatedItems[$productId]['qty'] += (float)$row['qty'];
                $aggregatedItems[$productId]['base_qty'] += (float)($row['base_qty'] ?? 0);
                $aggregatedItems[$productId]['discount'] += (float)($row['discount'] ?? 0);
                $aggregatedItems[$productId]['line_total'] += (float)($row['line_total'] ?? 0);
                $aggregatedItems[$productId]['net_total'] += (float)($row['net_total'] ?? 0);
                $aggregatedItems[$productId]['rows'][] = $row;
            }

            // 4. Calculate Total Ingredients Needed & Validate Masterfiles
            $totalIngredientsNeeded = []; // ItemCode -> ['needed' => qty, 'bom_entry' => model, 'sap_product' => model]
            $posMasterfiles = []; // Cache POSMasterfile models

            foreach ($aggregatedItems as $productId => $itemData) {
                $posMasterfile = POSMasterfile::whereRaw('UPPER(POSCode) = ?', [$productId])->first();

                if (!$posMasterfile) {
                    $this->addSkippedGroup($receiptRows, "POS Masterfile not found for Product ID: $productId");
                    return;
                }
                $posMasterfiles[$productId] = $posMasterfile;

                $bomIngredients = POSMasterfileBOM::where('POSCode', $posMasterfile->POSCode)->get();

                if ($bomIngredients->isNotEmpty()) {
                    foreach ($bomIngredients as $ingredientBOM) {
                        $sapProduct = SAPMasterfile::where('ItemCode', $ingredientBOM->ItemCode)
                            ->where(function ($query) use ($ingredientBOM) {
                                $query->whereRaw('UPPER(BaseUOM) = ?', [strtoupper($ingredientBOM->BOMUOM)])
                                    ->orWhereRaw('UPPER(AltUOM) = ?', [strtoupper($ingredientBOM->BOMUOM)]);
                            })
                            ->first();

                        if (!$sapProduct) {
                            $this->addSkippedGroup($receiptRows, "SAP Masterfile not found for ingredient '{$ingredientBOM->ItemCode}' (POS Item: {$posMasterfile->POSDescription})");
                            return;
                        }

                        $needed = (float)$ingredientBOM->BOMQty * $itemData['qty'];
                        
                        if (!isset($totalIngredientsNeeded[$sapProduct->ItemCode])) {
                            $totalIngredientsNeeded[$sapProduct->ItemCode] = [
                                'qty' => 0,
                                'sap_product' => $sapProduct,
                                'uom' => $ingredientBOM->BOMUOM,
                                'details' => [] // Track which POS items contribute to this
                            ];
                        }
                        $totalIngredientsNeeded[$sapProduct->ItemCode]['qty'] += $needed;
                        $totalIngredientsNeeded[$sapProduct->ItemCode]['details'][] = "{$posMasterfile->POSDescription} (x{$itemData['qty']})";
                    }
                }
            }

            // 5. Check SOH for ALL Aggregated Ingredients
            foreach ($totalIngredientsNeeded as $itemCode => $requirement) {
                $sapProduct = $requirement['sap_product'];
                $requiredQty = $requirement['qty'];

                // Calculate SOH
                $currentSOH = DB::table('product_inventory_stock_managers')
                    ->where('store_branch_id', $branch->id)
                    ->where('product_inventory_id', $sapProduct->id)
                    ->sum(DB::raw("CASE 
                        WHEN action IN ('add', 'add_quantity') THEN quantity 
                        WHEN action = 'out' THEN -quantity 
                        ELSE 0 
                    END"));

                if ($requiredQty > $currentSOH) {
                    $variance = $requiredQty - $currentSOH;
                    // Fail the ENTIRE receipt
                    $this->addSkippedGroup($receiptRows, 
                        "Insufficient balance for ingredient '{$sapProduct->ItemDescription}' ($itemCode). Receipt Needs: $requiredQty, SOH: $currentSOH.", 
                        [
                            'failed_ingredient_code' => $itemCode, // Pass the code of the failing ingredient
                            'failed_ingredient_desc' => $sapProduct->ItemDescription,
                            'uom' => $requirement['uom'],
                            'variance' => $variance,
                            'current_soh' => $currentSOH,
                            'total_deduction' => $requiredQty
                        ]
                    );
                    return;
                }
            }

            // 6. Create Transaction and Deduct
            DB::beginTransaction();
            try {
                $transaction = StoreTransaction::create([
                    'store_branch_id' => $branch->id,
                    'order_date' => $this->transformDate($date),
                    'posted' => $posted,
                    'tim_number' => $tm,
                    'receipt_number' => $receiptNumber,
                ]);

                foreach ($aggregatedItems as $productId => $itemData) {
                    $posMasterfile = $posMasterfiles[$productId];

                    // Create Item
                    $transaction->store_transaction_items()->create([
                        'product_id' => $posMasterfile->id,
                        'base_quantity' => $itemData['base_qty'],
                        'quantity' => $itemData['qty'],
                        'price' => $itemData['price'],
                        'discount' => $itemData['discount'],
                        'line_total' => $itemData['line_total'],
                        'net_total' => $itemData['net_total'],
                    ]);

                    // Deduct Ingredients (Calculated per item again to record specific usage logs? 
                    // Or bulk deduct? The original code logged usage per POS Item. Let's keep that granularity for audit.)
                    
                    $bomIngredients = POSMasterfileBOM::where('POSCode', $posMasterfile->POSCode)->get();
                    foreach ($bomIngredients as $ingredientBOM) {
                        // Re-fetch SAP Product to be safe/consistent or use cached if confident. 
                        // We need ID and Cost.
                        $sapProduct = SAPMasterfile::where('ItemCode', $ingredientBOM->ItemCode)->first(); // Simplified lookup as we validated UOM earlier
                        
                        $usedQty = (float)$ingredientBOM->BOMQty * $itemData['qty'];

                        ProductInventoryStockManager::create([
                            'product_inventory_id' => $sapProduct->id,
                            'store_branch_id' => $branch->id,
                            'cost_center_id' => null,
                            'quantity' => $usedQty,
                            'action' => 'out',
                            'unit_cost' => (float)$ingredientBOM->UnitCost,
                            'total_cost' => (float)$ingredientBOM->UnitCost * $usedQty,
                            'transaction_date' => $transaction->order_date,
                            'remarks' => "Deducted from store transaction (Receipt No. {$receiptNumber}) for POS item '{$posMasterfile->POSDescription}' ingredient '{$sapProduct->ItemDescription}'"
                        ]);
                    }
                }

                DB::commit();
                $this->createdCount++;

            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            $this->addSkippedGroup($receiptRows, 'Error processing receipt: ' . $e->getMessage());
            Log::error('Receipt Processing Error', ['error' => $e->getMessage(), 'receipt' => $receiptNumber]);
        }
    }

        private function addSkippedGroup($receiptRows, $reason, $extraData = [])
        {
            // Re-aggregate specifically for reporting so we have the Total Qty per item
            $aggregatedItems = [];
            foreach ($receiptRows as $row) {
                $productId = strtoupper(trim($row['product_id']));
                
                if (!isset($aggregatedItems[$productId])) {
                    $aggregatedItems[$productId] = [
                        'product_id' => $productId,
                        'product_name' => $row['product_name'],
                        'uom' => $row['uom'] ?? null,
                        'qty' => 0,
                        'base_qty' => 0,
                        'price' => $row['price'],
                        'discount' => 0,
                        'line_total' => 0,
                        'net_total' => 0,
                        'branch' => $row['branch'],
                        'receipt_no' => $row['receipt_no'],
                        '__row_number' => $row['__row_number'],
                        'date' => $row['date'],
                    ];
                }
                
                $aggregatedItems[$productId]['qty'] += (float)$row['qty'];
                $aggregatedItems[$productId]['base_qty'] += (float)($row['base_qty'] ?? 0);
                $aggregatedItems[$productId]['discount'] += (float)($row['discount'] ?? 0);
                $aggregatedItems[$productId]['line_total'] += (float)($row['line_total'] ?? 0);
                $aggregatedItems[$productId]['net_total'] += (float)($row['net_total'] ?? 0);
            }
    
            foreach ($aggregatedItems as $item) {
                $bomQtyDeduction = 'N/A';
                
                // If we have a specific failing ingredient, check if THIS item uses it
                if (isset($extraData['failed_ingredient_code'])) {
                    $posCode = strtoupper(trim($item['product_id']));
                    $failedIngredientCode = $extraData['failed_ingredient_code'];
                    
                    // Lookup BOM Qty
                    $bomEntry = POSMasterfileBOM::where('POSCode', $posCode)
                        ->where('ItemCode', $failedIngredientCode)
                        ->first();
                        
                    if ($bomEntry) {
                        $bomQtyDeduction = $bomEntry->BOMQty;
                    }
                }
    
                // Construct the skipped row manually to prevent overwriting POS details with Ingredient details
                $this->skippedRows[] = [
                    'row_number' => $item['__row_number'],
                    'reason' => $reason,
                    // CORRECTED: Use POS Item details
                    'item_code' => $item['product_id'],
                    'item_description' => $item['product_name'] ?? null,
                    'uom' => $item['uom'] ?? null,
                    'store_code' => $item['branch'],
                    'receipt_number' => $item['receipt_no'],
                    'qty' => $item['qty'], // Total Qty
                    
                    // Dynamic BOM Qty
                    'bom_qty_deduction' => $bomQtyDeduction,
                    
                    // Global Receipt Failure Details (Ingredient context)
                    'total_deduction' => $extraData['total_deduction'] ?? 'N/A',
                    'variance' => $extraData['variance'] ?? 'N/A',
                    'current_soh' => $extraData['current_soh'] ?? 'N/A',
                    'date_of_sales' => $item['date'],
                ];
            }
        }
    private function transformDate($value)
    {
        Log::debug('TransformDate: Initial value and type', ['value' => $value, 'type' => gettype($value)]);

        try {
            if (empty($value)) {
                // Log::warning('TransformDate: Date value is empty'); // Reduce noise
                throw new Exception('Date value is empty');
            }

            if ($value instanceof Carbon) {
                return $value->format('Y-m-d');
            }

            if (is_string($value)) {
                try {
                    $date = Carbon::createFromFormat('m/d/Y', $value);
                    if ($date !== false) {
                        return $date->format('Y-m-d');
                    }
                } catch (\InvalidArgumentException $e) {
                    // Ignore
                }
            }

            if (is_numeric($value)) {
                $excelDate = Carbon::createFromDate(1900, 1, 1)->addDays((int)$value - 2);
                return $excelDate->format('Y-m-d');
            }

            if (is_string($value)) {
                try {
                    $date = Carbon::parse($value);
                    return $date->format('Y-m-d');
                } catch (\Exception $e) {
                    // Ignore
                }
            }

            throw new Exception('Invalid date format');
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function getSkippedRows(): array
    {
        return $this->skippedRows;
    }

    public function getCreatedCount(): int
    {
        return $this->createdCount;
    }
}
