<?php

namespace App\Http\Controllers;

use App\Http\Services\StoreTransactionService;
use App\Models\POSMasterfile; // Added
use App\Models\POSMasterfileBOM; // Added
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\SAPMasterfile; // Added
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use Carbon\Carbon;
use Exception; // Added for explicit error handling
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class StoreTransactionApprovalController extends Controller
{
    protected $storeTransactionService;

    public function __construct(StoreTransactionService $storeTransactionService)
    {
        $this->storeTransactionService = $storeTransactionService;
    }

    public function mainIndex()
    {
        $from = request('from') ? Carbon::parse(request('from'))->format('Y-m-d') : '1999-01-01';
        $to = request('to') ? Carbon::parse(request('to'))->format('Y-m-d') : Carbon::today()->addMonth();

        $branches = StoreBranch::options();
        $branchId = request('branchId') ?? ($branches->first() ? $branches->first()['value'] : null); // Safely get first branch ID

        $transactions = StoreTransaction::query()
            ->where('is_approved', 'false')
            ->leftJoin('store_transaction_items', 'store_transactions.id', '=', 'store_transaction_items.store_transaction_id')
            ->whereBetween('order_date', [$from, $to])
            ->select(
                'store_transactions.order_date',
                DB::raw('COUNT(DISTINCT store_transactions.id) as transaction_count'),
                DB::raw('SUM(store_transaction_items.net_total) as net_total')
            )
            ->where('store_transactions.store_branch_id', $branchId)
            ->groupBy('store_transactions.order_date')
            ->orderBy('store_transactions.order_date', 'desc')
            ->get()
            ->map(function ($transaction) {
                return [
                    'order_date' => $transaction->order_date,
                    'transaction_count' => $transaction->transaction_count,
                    'net_total' => number_format($transaction->net_total ?? 0, 2, '.', ''), // Use number_format
                ];
            });


        // Fetch branches options again, as it might be used in the view for the dropdown
        $branches = StoreBranch::options();

        return Inertia::render('StoreTransactionApproval/MainIndex', [
            'filters' => request()->only(['from', 'to', 'branchId', 'search']),
            'branches' => $branches,
            'transactions' => $transactions
        ]);
    }
    public function index()
    {
        $transactions = $this->storeTransactionService->getStoreTransactionsForApprovalList();
        $branches = StoreBranch::options(); // Re-fetch branches as it's often needed for filters/dropdowns in the view

        return Inertia::render('StoreTransactionApproval/Index', [
            'transactions' => $transactions,
            'filters' => request()->only(['from', 'to', 'branchId', 'search']),
            'branches' => $branches,
            'order_date' => request('order_date')
        ]);
    }

    public function show(StoreTransaction $storeTransaction)
    {
        // Eager load posMasterfile on store_transaction_items
        $transaction = $storeTransaction->load(['store_transaction_items.posMasterfile', 'store_branch']);
        $transaction = $this->storeTransactionService->getTransactionDetails($transaction);

        return Inertia::render('StoreTransactionApproval/Show', [
            'transaction' => $transaction
        ]);
    }

    public function approveSelectedTransactions(Request $request)
    {
        $validated = $request->validate(['id' => ['required', 'array']]);

        foreach ($validated['id'] as $order_date) {
            $storeTransactions = StoreTransaction::with(['store_transaction_items.posMasterfile.posMasterfileBOMs']) // Eager load the new relationships
                ->where('order_date', $order_date)
                ->where('is_approved', 'false')
                ->get();

            foreach ($storeTransactions as $storeTransaction) {
                DB::beginTransaction();
                try {
                    $storeTransaction->update(['is_approved' => true]);
                    $branchId = $storeTransaction->store_branch_id;

                    foreach ($storeTransaction->store_transaction_items as $item) {
                        // Access BOMs through the posMasterfile relationship
                        $bomIngredients = $item->posMasterfile->posMasterfileBOMs;

                        foreach ($bomIngredients as $ingredientBOM) {
                            // Find the SAPMasterfile entry for the ingredient based on ItemCode and UOM
                            $sapProduct = SAPMasterfile::where('ItemCode', $ingredientBOM->ItemCode)
                                                        ->where(function ($query) use ($ingredientBOM) {
                                                            $query->whereRaw('UPPER(BaseUOM) = ?', [strtoupper($ingredientBOM->BOMUOM)])
                                                                  ->orWhereRaw('UPPER(AltUOM) = ?', [strtoupper($ingredientBOM->BOMUOM)]);
                                                        })
                                                        ->first();

                            if (!$sapProduct) {
                                throw new Exception("SAP Masterfile entry not found for ItemCode: '{$ingredientBOM->ItemCode}' with UOM: '{$ingredientBOM->BOMUOM}' for POS item {$item->posMasterfile->POSDescription}.");
                            }

                            // Get the ProductInventoryStock for the specific SAP product and branch
                            $productStock = ProductInventoryStock::where('product_inventory_id', $sapProduct->id)
                                ->where('store_branch_id', $branchId)
                                ->first();

                            if (!$productStock) {
                                throw new Exception("Product inventory stock not found for SAP Item '{$sapProduct->ItemDescription}' in branch '{$storeTransaction->store_branch->location_code}'.");
                            }

                            // Calculate the total quantity of this ingredient needed
                            $requiredQuantity = $ingredientBOM->BOMQty * $item->quantity;
                            $stockOnHand = $productStock->quantity - $productStock->used;

                            if ($requiredQuantity > $stockOnHand) {
                                throw new Exception("Insufficient inventory for '{$sapProduct->ItemDescription}' in branch '{$storeTransaction->store_branch->location_code}'. Required: {$requiredQuantity}, Available: {$stockOnHand}.");
                            }

                            // Increment the 'used' quantity
                            $productStock->increment('used', $requiredQuantity);

                            // Log the inventory deduction
                            ProductInventoryStockManager::create([
                                'product_inventory_id' => $sapProduct->id,
                                'store_branch_id' => $branchId,
                                'cost_center_id' => null,
                                'quantity' => $requiredQuantity,
                                'action' => 'deduct',
                                'transaction_date' => $storeTransaction->order_date,
                                'remarks' => "Deducted from store transaction (Receipt No. {$storeTransaction->receipt_number}) for POS item '{$item->posMasterfile->POSDescription}' ingredient '{$sapProduct->ItemDescription}'"
                            ]);
                        }
                    }
                    DB::commit();
                } catch (Exception $e) {
                    DB::rollBack();
                    Log::error("Error approving transaction {$storeTransaction->id} for date {$order_date}: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                    return back()->with('error', 'Failed to approve transactions: ' . $e->getMessage());
                }
            }
        }
        return back()->with('success', 'Selected store transactions approved successfully.');
    }

    public function approveAllTransactions()
    {
        $storeTransactions = StoreTransaction::with(['store_transaction_items.posMasterfile.posMasterfileBOMs']) // Eager load the new relationships
            ->where('is_approved', 'false')
            ->get();

        foreach ($storeTransactions as $storeTransaction) {
            DB::beginTransaction();
            try {
                $storeTransaction->update(['is_approved' => true]);
                $branchId = $storeTransaction->store_branch_id;

                foreach ($storeTransaction->store_transaction_items as $item) {
                    // Access BOMs through the posMasterfile relationship
                    $bomIngredients = $item->posMasterfile->posMasterfileBOMs;

                    foreach ($bomIngredients as $ingredientBOM) {
                        // Find the SAPMasterfile entry for the ingredient based on ItemCode and UOM
                        $sapProduct = SAPMasterfile::where('ItemCode', $ingredientBOM->ItemCode)
                                                    ->where(function ($query) use ($ingredientBOM) {
                                                        $query->whereRaw('UPPER(BaseUOM) = ?', [strtoupper($ingredientBOM->BOMUOM)])
                                                              ->orWhereRaw('UPPER(AltUOM) = ?', [strtoupper($ingredientBOM->BOMUOM)]);
                                                    })
                                                    ->first();

                        if (!$sapProduct) {
                            throw new Exception("SAP Masterfile entry not found for ItemCode: '{$ingredientBOM->ItemCode}' with UOM: '{$ingredientBOM->BOMUOM}' for POS item {$item->posMasterfile->POSDescription}.");
                        }

                        $productStock = ProductInventoryStock::where('product_inventory_id', $sapProduct->id)
                            ->where('store_branch_id', $branchId)
                            ->first();

                        if (!$productStock) {
                            throw new Exception("Product inventory stock not found for SAP Item '{$sapProduct->ItemDescription}' in branch '{$storeTransaction->store_branch->location_code}'.");
                        }

                        // Calculate the total quantity of this ingredient needed
                        $requiredQuantity = $ingredientBOM->BOMQty * $item->quantity;
                        $stockOnHand = $productStock->quantity - $productStock->used;

                        if ($requiredQuantity > $stockOnHand) {
                            throw new Exception("Insufficient inventory for '{$sapProduct->ItemDescription}' in branch '{$storeTransaction->store_branch->location_code}'. Required: {$requiredQuantity}, Available: {$stockOnHand}.");
                        }

                        // Increment the 'used' quantity
                        $productStock->increment('used', $requiredQuantity);

                        // Log the inventory deduction
                        ProductInventoryStockManager::create([
                            'product_inventory_id' => $sapProduct->id,
                            'store_branch_id' => $branchId,
                            'cost_center_id' => null,
                            'quantity' => $requiredQuantity,
                            'action' => 'deduct',
                            'transaction_date' => $storeTransaction->order_date,
                            'remarks' => "Deducted from store transaction (Receipt No. {$storeTransaction->receipt_number}) for POS item '{$item->posMasterfile->POSDescription}' ingredient '{$sapProduct->ItemDescription}'"
                        ]);
                    }
                }
                DB::commit();
            } catch (Exception $e) {
                DB::rollBack();
                Log::error("Error approving all transactions (ID: {$storeTransaction->id}): " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                return back()->with('error', 'Failed to approve all transactions: ' . $e->getMessage());
            }
        }
        return back()->with('success', 'All pending store transactions approved successfully.');
    }
}
