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

            // 4. NEW: Validate Ingredient Inventory with proper logic
            $totalIngredientsNeeded = []; // ItemCode -> ['needed' => qty, 'bom_entry' => model, 'sap_product' => model]
            $posMasterfiles = []; // Cache POSMasterfile models

            // Check each POS item individually for missing masterfiles
            $missingPosMasterfiles = [];
            foreach ($aggregatedItems as $productId => $itemData) {
                $posMasterfile = POSMasterfile::whereRaw('UPPER(POSCode) = ?', [$productId])->first();

                if (!$posMasterfile) {
                    $missingPosMasterfiles[] = $productId;
                } else {
                    $posMasterfiles[$productId] = $posMasterfile;
                }
            }
            
            // If any POS masterfiles are missing, skip each item with its specific error
            if (!empty($missingPosMasterfiles)) {
                foreach ($aggregatedItems as $productId => $itemData) {
                    if (in_array($productId, $missingPosMasterfiles)) {
                        // Create individual skipped row for this specific item
                        $this->skippedRows[] = [
                            'row_number' => $itemData['__row_number'],
                            'reason' => "POS Masterfile not found for Product ID: {$productId}",
                            'item_code' => $productId,
                            'item_description' => $itemData['product_name'],
                            'uom' => $itemData['uom'],
                            'store_code' => $itemData['branch'],
                            'receipt_number' => $receiptNumber,
                            'qty' => $itemData['qty'],
                            'bom_qty_deduction' => 'N/A',
                            'total_deduction' => 'N/A',
                            'variance' => 'N/A',
                            'current_soh' => 'N/A',
                            'date_of_sales' => $itemData['date'],
                        ];
                    }
                }
                return;
            }
            
            foreach ($aggregatedItems as $productId => $itemData) {
                $posMasterfile = $posMasterfiles[$productId];

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

                // MODIFIED: Only validate sufficiency if SOH is positive
                if ($currentSOH > 0 && $requiredQty > $currentSOH) {
                    $variance = $requiredQty - $currentSOH;
                    // Add each POS item that uses this ingredient with correct description
                    foreach ($aggregatedItems as $productId => $itemData) {
                        $bomEntry = POSMasterfileBOM::where('POSCode', $productId)
                            ->where('ItemCode', $itemCode)
                            ->first();
                            
                        if ($bomEntry) {
                            $bomQtyDeduction = POSMasterfileBOM::where('POSCode', $productId)
                                ->where('ItemCode', $itemCode)
                                ->sum('BOMQty');
                                
                            $reason = "Insufficient balance for ingredient '{$bomEntry->ItemDescription}' ({$itemCode}). Receipt Needs: {$requiredQty}, SOH: {$currentSOH}.";
                            
                            $this->skippedRows[] = [
                                'row_number' => $itemData['__row_number'],
                                'reason' => $reason,
                                'item_code' => $productId,
                                'item_description' => $itemData['product_name'],
                                'uom' => $itemData['uom'],
                                'store_code' => $itemData['branch'],
                                'receipt_number' => $receiptNumber,
                                'qty' => $itemData['qty'],
                                'bom_qty_deduction' => $bomQtyDeduction,
                                'total_deduction' => $requiredQty,
                                'variance' => $variance,
                                'current_soh' => $currentSOH,
                                'date_of_sales' => $itemData['date'],
                            ];
                        }
                    }
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

                    // Deduct Ingredients
                    $bomIngredients = POSMasterfileBOM::where('POSCode', $posMasterfile->POSCode)->get();
                    
                    // Aggregate ingredients by SAP Item ID (Step 5 logic)
                    $ingredientsToDeduct = [];

                    foreach ($bomIngredients as $ingredientBOM) {
                        // Re-fetch SAP Product to ensure we have the correct ID for deduction
                        $sapProduct = SAPMasterfile::where('ItemCode', $ingredientBOM->ItemCode)
                            ->where(function ($query) use ($ingredientBOM) {
                                $query->whereRaw('UPPER(BaseUOM) = ?', [strtoupper($ingredientBOM->BOMUOM)])
                                      ->orWhereRaw('UPPER(AltUOM) = ?', [strtoupper($ingredientBOM->BOMUOM)]);
                            })->first();
                        
                        if ($sapProduct) {
                            $key = $sapProduct->id;
                            if (!isset($ingredientsToDeduct[$key])) {
                                $ingredientsToDeduct[$key] = [
                                    'sap_product' => $sapProduct,
                                    'total_qty' => 0,
                                    'unit_cost' => (float)$ingredientBOM->UnitCost,
                                ];
                            }
                            $usedQty = (float)$ingredientBOM->BOMQty * $itemData['qty'];
                            $ingredientsToDeduct[$key]['total_qty'] += $usedQty;
                        }
                    }

                    // Perform Deduction
                    foreach ($ingredientsToDeduct as $deduction) {
                        $sapProduct = $deduction['sap_product'];
                        $finalQty = $deduction['total_qty'];

                        // Calculate SOH again to enforce "SOH > 0" rule for deduction
                        $currentSOH = DB::table('product_inventory_stock_managers')
                            ->where('store_branch_id', $branch->id)
                            ->where('product_inventory_id', $sapProduct->id)
                            ->sum(DB::raw("CASE 
                                WHEN action IN ('add', 'add_quantity') THEN quantity 
                                WHEN action = 'out' THEN -quantity 
                                ELSE 0 
                            END"));

                        // Only deduct if SOH is positive
                        if ($currentSOH > 0) {
                            ProductInventoryStockManager::create([
                                'product_inventory_id' => $sapProduct->id,
                                'store_branch_id' => $branch->id,
                                'cost_center_id' => null,
                                'quantity' => $finalQty,
                                'action' => 'out',
                                'unit_cost' => $deduction['unit_cost'],
                                'total_cost' => $deduction['unit_cost'] * $finalQty,
                                'transaction_date' => $transaction->order_date,
                                'remarks' => "Deducted from store transaction (Receipt No. {$receiptNumber}) for POS item '{$posMasterfile->POSDescription}' ingredient '{$sapProduct->ItemDescription}'"
                            ]);
                        }
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
                $shouldSkipThisItem = true;
                
                // Always use POS Item details for display
                $displayItemCode = $item['product_id'];
                $displayItemDesc = $item['product_name'] ?? null;
                $displayUom = $item['uom'] ?? null;

                // If we have a specific failing ingredient, only skip items that actually use it
                if (isset($extraData['failed_ingredient_code'])) {
                    $posCode = $item['product_id'];
                    $failedIngredientCode = $extraData['failed_ingredient_code'];
                    
                    // Check if this POS item uses the failing ingredient
                    $bomEntry = POSMasterfileBOM::where('POSCode', $posCode)
                        ->where('ItemCode', $failedIngredientCode)
                        ->first();
                        
                    if ($bomEntry) {
                        $bomQtyDeduction = POSMasterfileBOM::where('POSCode', $posCode)
                            ->where('ItemCode', $failedIngredientCode)
                            ->sum('BOMQty');
                        $shouldSkipThisItem = true;
                    } else {
                        $shouldSkipThisItem = false; // This POS item doesn't use the failing ingredient
                    }
                }
    
                // Only add to skipped rows if this item should be skipped
                if ($shouldSkipThisItem) {
                    $this->skippedRows[] = [
                        'row_number' => $item['__row_number'],
                        'reason' => $reason,
                        // Always show POS item details, not ingredient details
                        'item_code' => $displayItemCode,
                        'item_description' => $displayItemDesc,
                        'uom' => $displayUom,
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

    private function validateIngredientInventory($aggregatedItems, $branch)
    {
        $totalIngredientsNeeded = [];

        foreach ($aggregatedItems as $productId => $itemData) {
            // Step 1: Get POS Masterfile for Excel Product ID
            $posMasterfile = POSMasterfile::whereRaw('UPPER(POSCode) = ?', [$productId])->first();

            if (!$posMasterfile) {
                throw new Exception("POS Masterfile not found for Product ID: $productId");
            }

            // Step 2: Get all BOM ingredients for this POS item
            $bomIngredients = POSMasterfileBOM::where('POSCode', $posMasterfile->POSCode)->get();

            foreach ($bomIngredients as $ingredientBOM) {
                // Step 3: Match BOM ingredient to SAP Masterfile with exact UOM matching
                $sapMasterfile = SAPMasterfile::where('ItemCode', $ingredientBOM->ItemCode)
                    ->where(function ($query) use ($ingredientBOM) {
                        $query->whereRaw('UPPER(AltUOM) = ?', [strtoupper($ingredientBOM->BOMUOM)])
                              ->orWhereRaw('UPPER(BaseUOM) = ?', [strtoupper($ingredientBOM->BOMUOM)]);
                    })
                    ->first();

                if (!$sapMasterfile) {
                    throw new Exception(
                        "SAP Masterfile not found for ingredient '{$ingredientBOM->ItemCode}' " .
                        "with UOM '{$ingredientBOM->BOMUOM}' (POS Item: {$posMasterfile->POSDescription})"
                    );
                }

                // Step 4: Calculate total ingredient needed for this POS item
                $key = $sapMasterfile->id . '_' . $ingredientBOM->BOMUOM;
                $neededQty = (float)$ingredientBOM->BOMQty * $itemData['qty'];

                // Step 5: Aggregate ingredients across all POS items in receipt
                if (!isset($totalIngredientsNeeded[$key])) {
                    $totalIngredientsNeeded[$key] = [
                        'sap_masterfile_id' => $sapMasterfile->id,
                        'item_code' => $ingredientBOM->ItemCode,
                        'item_description' => $sapMasterfile->ItemDescription,
                        'uom' => $ingredientBOM->BOMUOM,
                        'total_needed' => 0,
                        'pos_items_using' => []
                    ];
                }

                $totalIngredientsNeeded[$key]['total_needed'] += $neededQty;
                $totalIngredientsNeeded[$key]['pos_items_using'][] = "{$posMasterfile->POSDescription} (x{$itemData['qty']})";
            }
        }

        // Step 6: Validate SOH for each aggregated ingredient
        foreach ($totalIngredientsNeeded as $ingredientKey => $ingredientData) {
            $currentSOH = DB::table('product_inventory_stock_managers')
                ->where('store_branch_id', $branch->id)
                ->where('product_inventory_id', $ingredientData['sap_masterfile_id'])
                ->sum(DB::raw("CASE
                    WHEN action IN ('add', 'add_quantity') THEN quantity
                    WHEN action = 'out' THEN -quantity
                    ELSE 0
                END"));

            if ($ingredientData['total_needed'] > $currentSOH) {
                $variance = $ingredientData['total_needed'] - $currentSOH;
                throw new Exception(
                    "Insufficient inventory for ingredient '{$ingredientData['item_description']}' " .
                    "({$ingredientData['item_code']}). Required: {$ingredientData['total_needed']} {$ingredientData['uom']}, " .
                    "Available: {$currentSOH} {$ingredientData['uom']}. Used by: " . implode(', ', $ingredientData['pos_items_using'])
                );
            }
        }

        return $totalIngredientsNeeded;
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
