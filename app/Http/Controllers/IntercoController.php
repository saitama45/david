<?php

namespace App\Http\Controllers;

use App\Http\Services\IntercoService;
use App\Models\StoreOrder;
use App\Models\StoreBranch;
use App\Models\SAPMasterfile;
use App\Models\ProductInventoryStock;
use App\Enums\IntercoStatus;
use App\Http\Requests\IntercoRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\JsonResponse;

class IntercoController extends Controller
{
    protected $intercoService;

    public function __construct(IntercoService $intercoService)
    {
        $this->intercoService = $intercoService;
    }

    /**
     * Display a listing of interco orders
     */
    public function index(Request $request)
    {
        $status = $request->get('status') ?? 'open';
        $search = $request->get('search');

        // Get current user's assigned store branches
        $user = Auth::user();
        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');

        $query = StoreOrder::whereNotNull('interco_number')
            ->whereNotNull('sending_store_branch_id')
            ->with(['store_branch', 'sendingStore', 'encoder', 'store_order_items.sapMasterfile']);

        // Apply store-based filtering for ALL users based on receiving store (store_branch_id)
        // This matches the IntercoApproval pattern and UserAssignedStoreBranch model
        if ($assignedStoreIds->isNotEmpty()) {
            $query->where(function($q) use ($assignedStoreIds) {
                $q->whereIn('store_branch_id', $assignedStoreIds)
                  ->orWhereIn('sending_store_branch_id', $assignedStoreIds);
            });
        } else {
            // User has no assigned stores - return empty results
            $query->whereRaw('1 = 0');
        }

        // Filter by status
        if ($status) {
            $query->where('interco_status', $status);
        }

        // Search functionality
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('interco_number', 'like', "%{$search}%")
                  ->orWhere('interco_reason', 'like', "%{$search}%")
                  ->orWhereHas('store_branch', function($sq) use ($search) {
                      $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('branch_code', 'like', "%{$search}%");
                  })
                  ->orWhereHas('sendingStore', function($sq) use ($search) {
                      $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('branch_code', 'like', "%{$search}%");
                  });
            });
        }

        $orders = $query->orderBy('id', 'desc')
                     ->orderBy('order_date', 'desc')
                     ->paginate(10);
        $statistics = $this->intercoService->getIntercoStatistics(
            $assignedStoreIds->isNotEmpty() ? $assignedStoreIds->toArray() : null
        );

        return Inertia::render('Interco/Index', [
            'orders' => $orders,
            'statistics' => $statistics,
            'filters' => [
                'status' => $status,
                'search' => $search,
            ],
            'statusOptions' => collect(IntercoStatus::cases())->map(fn($status) => [
                'value' => $status->value,
                'label' => $status->getLabel(),
                'color' => $status->getColor(),
            ]),
            'permissions' => [
                'can_create' => $user->hasPermissionTo('create interco requests'),
                'can_edit' => $user->hasPermissionTo('edit interco requests'),
                'can_approve' => $user->hasPermissionTo('approve interco requests'),
                'can_commit' => $user->hasPermissionTo('commit interco requests'),
            ]
        ]);
    }

    /**
     * Show the form for creating a new interco order
     */
    public function create()
    {
        $user = auth()->user();
        $branches = StoreBranch::options();
        $items = SAPMasterfile::where('is_active', true)->orderBy('ItemDescription')->get();

        return Inertia::render('Interco/Create', [
            'branches' => $branches,
            'items' => $items->map(fn($item) => [
                'id' => $item->id,
                'item_code' => $item->ItemCode,
                'description' => $item->ItemDescription,
                'uom' => $item->BaseUOM,
                'alt_uom' => $item->AltUOM,
                'cost_per_quantity' => 0, // Default value as column doesn't exist
                'stock' => null, // Stock will be populated via API call
                'is_available' => true, // Default availability
            ]),
            'user_store_branch_id' => $user->store_branch_id,
        ]);
    }

    /**
     * Store a newly created interco order
     */
    public function store(IntercoRequest $request)
    {
        $user = auth()->user();
        $data = $request->validated();

        try {
            DB::beginTransaction();

            // Get stores with better error handling
            try {
                $receivingStore = StoreBranch::findOrFail($data['store_branch_id']);
                $sendingStore = StoreBranch::findOrFail($data['sending_store_branch_id']);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                throw new \Exception('One or both stores not found. Please check store selection.');
            }

            // Validate store difference
            if ($receivingStore->id === $sendingStore->id) {
                throw new \Exception('Sending and receiving stores cannot be the same.');
            }

            // Generate interco number with error handling
            try {
                $intercoNumber = $this->intercoService->generateIntercoNumber($receivingStore, $sendingStore);
            } catch (\Exception $e) {
                throw new \Exception('Failed to generate interco number: ' . $e->getMessage());
            }

            // Create the interco order with specific error handling
            try {
                $order = StoreOrder::create([
                    'encoder_id' => $user->id,
                    'store_branch_id' => $data['store_branch_id'],
                    'supplier_id' => 1, // Set default supplier for interco
                    'order_number' => $intercoNumber,
                    'order_date' => now(),
                    'order_status' => 'PENDING',
                    'interco_number' => $intercoNumber,
                    'sending_store_branch_id' => $data['sending_store_branch_id'],
                    'interco_reason' => $data['interco_reason'],
                    'transfer_date' => $data['transfer_date'],
                    'interco_status' => IntercoStatus::OPEN,
                    'variant' => 'INTERCO',
                    'remarks' => $data['remarks'] ?? null,
                ]);

      } catch (\Illuminate\Database\QueryException $e) {
                \Log::error('Database error creating interco order: ' . $e->getMessage());
                if (strpos($e->getMessage(), 'interco_number') !== false) {
                    throw new \Exception('Duplicate interco number detected. Please try again.');
                }
                throw new \Exception('Failed to create interco order in database.');
            }

            // Create order items with validation
            if (empty($data['items'])) {
                throw new \Exception('At least one item must be added to create an interco transfer.');
            }

            foreach ($data['items'] as $index => $itemData) {
                try {
                    // Validate item data
                    if (!isset($itemData['item_code']) || !isset($itemData['quantity_ordered'])) {
                        throw new \Exception("Item at position " . ($index + 1) . " has missing required fields.");
                    }

                    if ($itemData['quantity_ordered'] <= 0) {
                        throw new \Exception("Item quantity must be greater than 0 for item: " . $itemData['item_code']);
                    }

                    // Find the corresponding SAP masterfile record to establish the relationship
                    $sapMasterfile = SAPMasterfile::where('ItemCode', $itemData['item_code'])
                        ->where('AltUOM', $itemData['uom'])
                        ->where('is_active', true)
                        ->first();

                    if (!$sapMasterfile) {
                        throw new \Exception("SAP Masterfile not found for item code: " . $itemData['item_code'] . " with UOM: " . $itemData['uom'] . ". Please ensure the item exists in SAP master data with the specified UOM.");
                    }

                    $order->store_order_items()->create([
                        'item_code' => $itemData['item_code'],
                        'sap_masterfile_id' => $sapMasterfile->id, // CRITICAL: Set the relationship
                        'quantity_ordered' => $itemData['quantity_ordered'],
                        'quantity_approved' => $itemData['quantity_ordered'],
                        'quantity_commited' => $itemData['quantity_ordered'],
                        'cost_per_quantity' => $itemData['cost_per_quantity'] ?? 1.0,
                        'total_cost' => ($itemData['quantity_ordered'] * ($itemData['cost_per_quantity'] ?? 1.0)),
                        'uom' => $itemData['uom'] ?? 'PCS',
                        'remarks' => $itemData['remarks'] ?? null,
                    ]);
                } catch (\Exception $e) {
                    throw new \Exception("Failed to create item '" . ($itemData['item_code'] ?? 'Unknown') . "': " . $e->getMessage());
                }
            }

            DB::commit();

                    \Log::info("Interco order created successfully", [
                'order_id' => $order->id,
                'interco_number' => $intercoNumber,
                'user_id' => $user->id,
                'sending_store' => $sendingStore->id,
                'receiving_store' => $receivingStore->id,
                'items_count' => count($data['items'])
            ]);

            return redirect()->route('interco.show', $order->id)
                ->with('success', 'Interco transfer request created successfully with ' . count($data['items']) . ' items.');

        } catch (\Exception $e) {
            DB::rollBack();

            // Log detailed error for debugging
            \Log::error('Interco order creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $user->id,
                'data' => $data
            ]);

            // Return user-friendly error message
            $errorMessage = 'Failed to create interco transfer request: ' . $e->getMessage();
            return back()->withInput()->withErrors(['error' => $errorMessage]);
        }
    }

    /**
     * Display the specified interco order
     */
    public function show(StoreOrder $interco)
    {
        $user = auth()->user();

        // Check if this is an interco order
        if (!$interco->isInterco()) {
            abort(404, 'Interco order not found');
        }

        // Load relationships
        $interco->load([
            'store_branch',
            'sendingStore',
            'encoder',
            'approver',
            'commiter',
            'store_order_items.sapMasterfile',
            'store_order_remarks.user'
        ]);

        // Debug: Log relationship loading
        \Log::info('Interco Order ID: ' . $interco->id);
        \Log::info('Sending Store ID: ' . $interco->sending_store_branch_id);
        \Log::info('Sending Store Data: ' . json_encode($interco->sendingStore));
        \Log::info('Store Order Items Count: ' . $interco->store_order_items->count());

        // Debug each item's relationship
        foreach ($interco->store_order_items as $item) {
            \Log::info('Item Code: ' . $item->item_code . ', SAP Masterfile: ' . json_encode($item->sapMasterfile));
        }

        // Log the complete data structure being sent to frontend
        \Log::info('Complete order data structure for Inertia:', [
            'id' => $interco->id,
            'from_store_name' => $interco->from_store_name,
            'to_store_name' => $interco->to_store_name,
            'sending_store_exists' => !is_null($interco->sendingStore),
            'items_count' => $interco->store_order_items->count(),
            'items_with_descriptions' => $interco->store_order_items->map(function($item) {
                return [
                    'item_code' => $item->item_code,
                    'description' => $item->item_description,
                    'uom' => $item->item_uom,
                    'sap_masterfile_exists' => !is_null($item->sapMasterfile)
                ];
            })->toArray()
        ]);

        return Inertia::render('Interco/Show', [
            'order' => $interco,
            'permissions' => [
                'can_edit' => $this->intercoService->canUserPerformAction($interco, 'edit', $user),
                'can_approve' => $this->intercoService->canUserPerformAction($interco, 'approve', $user),
                'can_commit' => $this->intercoService->canUserPerformAction($interco, 'commit', $user),
                'can_receive' => $this->intercoService->canUserPerformAction($interco, 'receive', $user),
            ],
            'statusTransitions' => $this->getAvailableStatusTransitions($interco->interco_status, $user),
        ]);
    }

    /**
     * Show the form for editing the specified interco order
     */
    public function edit(StoreOrder $interco)
    {
        $user = auth()->user();

        // Check if this is an interco order and can be edited
        if (!$interco->isInterco() || !$this->intercoService->canUserPerformAction($interco, 'edit', $user)) {
            abort(403, 'You cannot edit this interco order');
        }

        // Load all required relationships for proper pre-population
        $interco->load([
            'sendingStore',           // StoreBranch for sending store details
            'store_branch',           // StoreBranch for receiving store details
            'encoder',               // User for audit trail
            'store_order_items.sapMasterfile' // SAP Masterfile for item details
        ]);

        $branches = StoreBranch::options();
        $items = SAPMasterfile::where('is_active', true)->orderBy('ItemDescription')->get();

        // Debug: Log the branches data to investigate missing store options
        \Log::info('Interco Edit - Branches data:', [
            'branches_count' => $branches->count(),
            'branches_data' => $branches->toArray(),
            'order_sending_store_id' => $interco->sending_store_branch_id,
            'order_receiving_store_id' => $interco->store_branch_id
        ]);

        // Check if the required stores exist in the branches
        if ($interco->sending_store_branch_id) {
            $sendingStoreExists = $branches->contains('value', $interco->sending_store_branch_id);
            \Log::info('Sending store check:', [
                'sending_store_id' => $interco->sending_store_branch_id,
                'exists_in_options' => $sendingStoreExists
            ]);
        }

        if ($interco->store_branch_id) {
            $receivingStoreExists = $branches->contains('value', $interco->store_branch_id);
            \Log::info('Receiving store check:', [
                'receiving_store_id' => $interco->store_branch_id,
                'exists_in_options' => $receivingStoreExists
            ]);
        }

        return Inertia::render('Interco/Edit', [
            'order' => $interco,
            'branches' => $branches,
            'user_store_branch_id' => $user->store_branch_id, // REQUIRED for form defaults
            'items' => $items->map(fn($item) => [
                'id' => $item->id,
                'item_code' => $item->ItemCode,
                'description' => $item->ItemDescription,
                'uom' => $item->BaseUOM,
                'alt_uom' => $item->AltUOM,
                'cost_per_quantity' => 0, // Default value as column doesn't exist
                'stock' => null, // Stock will be populated via API call
                'is_available' => true, // Default availability
            ]),
        ]);
    }

    /**
     * Update the specified interco order
     */
    public function update(IntercoRequest $request, StoreOrder $interco)
    {
        $user = auth()->user();
        $data = $request->validated();

        // Check if this is an interco order and can be edited
        if (!$interco->isInterco() || !$this->intercoService->canUserPerformAction($interco, 'edit', $user)) {
            abort(403, 'You cannot edit this interco order');
        }

        try {
            DB::beginTransaction();

            // Update order details
            $interco->update([
                'store_branch_id' => $data['store_branch_id'],
                'sending_store_branch_id' => $data['sending_store_branch_id'],
                'interco_reason' => $data['interco_reason'],
                'transfer_date' => $data['transfer_date'],
                'remarks' => $data['remarks'] ?? null,
            ]);

            // Update existing items and remove deleted ones
            $itemIds = collect($data['items'])->pluck('id')->filter();
            $interco->store_order_items()->whereNotIn('id', $itemIds)->delete();

            // Create or update items
            foreach ($data['items'] as $itemData) {
                try {
                    // Validate item data
                    if (!isset($itemData['item_code']) || !isset($itemData['quantity_ordered'])) {
                        throw new \Exception("Item has missing required fields: item_code and quantity_ordered are required.");
                    }

                    if ($itemData['quantity_ordered'] <= 0) {
                        throw new \Exception("Item quantity must be greater than 0 for item: " . $itemData['item_code']);
                    }

                    // Find the corresponding SAP masterfile record to establish the relationship
                    $sapMasterfile = SAPMasterfile::where('ItemCode', $itemData['item_code'])
                        ->where('AltUOM', $itemData['uom'])
                        ->where('is_active', true)
                        ->first();

                    if (!$sapMasterfile) {
                        throw new \Exception("SAP masterfile not found for item code: " . $itemData['item_code'] . " with UOM: " . $itemData['uom'] . ". Please ensure the item exists in SAP master data with the specified UOM.");
                    }

                    if (isset($itemData['id'])) {
                        // Update existing item
                        $interco->store_order_items()->where('id', $itemData['id'])->update([
                            'item_code' => $itemData['item_code'],
                            'quantity_ordered' => $itemData['quantity_ordered'],
                            'quantity_approved' => $itemData['quantity_ordered'],
                            'quantity_commited' => $itemData['quantity_ordered'],
                            'cost_per_quantity' => $itemData['cost_per_quantity'],
                            'total_cost' => $itemData['quantity_ordered'] * $itemData['cost_per_quantity'],
                            'uom' => $itemData['uom'],
                            'remarks' => $itemData['remarks'] ?? null,
                            'sap_masterfile_id' => $sapMasterfile->id, // CRITICAL: Set the relationship
                        ]);
                    } else {
                        // Create new item
                        $interco->store_order_items()->create([
                            'item_code' => $itemData['item_code'],
                            'quantity_ordered' => $itemData['quantity_ordered'],
                            'quantity_approved' => $itemData['quantity_ordered'],
                            'quantity_commited' => $itemData['quantity_ordered'],
                            'cost_per_quantity' => $itemData['cost_per_quantity'],
                            'total_cost' => $itemData['quantity_ordered'] * $itemData['cost_per_quantity'],
                            'uom' => $itemData['uom'],
                            'remarks' => $itemData['remarks'] ?? null,
                            'sap_masterfile_id' => $sapMasterfile->id, // CRITICAL: Set the relationship
                        ]);
                    }
                } catch (\Exception $e) {
                    throw new \Exception("Failed to process item '" . ($itemData['item_code'] ?? 'Unknown') . "': " . $e->getMessage());
                }
            }

            DB::commit();

            return redirect()->route('interco.show', $interco->id)
                ->with('success', 'Interco transfer request updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->withErrors(['error' => 'Failed to update interco transfer request: ' . $e->getMessage()]);
        }
    }

    /**
     * Get available status transitions for the current user
     */
    private function getAvailableStatusTransitions(?IntercoStatus $currentStatus, $user): array
    {
        if (!$currentStatus) {
            return [];
        }

        $transitions = [];

        switch ($currentStatus) {
            case IntercoStatus::OPEN:
                if ($user->hasPermissionTo('approve interco requests')) {
                    $transitions[] = [
                        'action' => 'approve',
                        'label' => 'Approve',
                        'nextStatus' => IntercoStatus::APPROVED->value,
                        'color' => 'blue',
                    ];
                    $transitions[] = [
                        'action' => 'disapprove',
                        'label' => 'Disapprove',
                        'nextStatus' => IntercoStatus::DISAPPROVED->value,
                        'color' => 'red',
                    ];
                }
                break;

            case IntercoStatus::APPROVED:
                if ($user->hasPermissionTo('commit interco requests')) {
                    $transitions[] = [
                        'action' => 'commit',
                        'label' => 'Commit',
                        'nextStatus' => IntercoStatus::COMMITTED->value,
                        'color' => 'yellow',
                    ];
                }
                break;

            case IntercoStatus::COMMITTED:
                // Only sending store can mark as in transit
                // This would be handled in the actual implementation based on the order context
                break;
        }

        return $transitions;
    }

    /**
     * Get available items for transfer from a specific sending store
     */
    public function getAvailableItems(Request $request): JsonResponse
    {
        $request->validate([
            'sending_store_id' => 'required|integer|exists:store_branches,id'
        ]);

        $sendingStoreId = $request->input('sending_store_id');
        $search = $request->input('search');

        try {
            // Modified query to show ALL items regardless of stock, with better filtering
            $queryStartTime = microtime(true);

            // Start with base query (using the working JOIN logic from original)
            $itemsQuery = SAPMasterfile::select([
                    'sap_masterfiles.id',
                    'sap_masterfiles.ItemCode',
                    'sap_masterfiles.ItemDescription',
                    'sap_masterfiles.BaseUOM',
                    'sap_masterfiles.AltUOM',
                    'product_inventory_stocks.quantity as stock_quantity'
                ])
                ->join('product_inventory_stocks', function($join) use ($sendingStoreId) {
                    $join->on('sap_masterfiles.id', '=', 'product_inventory_stocks.product_inventory_id')
                         ->where('product_inventory_stocks.store_branch_id', '=', $sendingStoreId)
                         ->where('product_inventory_stocks.quantity', '>', 0);
                })
                ->where('sap_masterfiles.is_active', true)
                ->whereNotNull('sap_masterfiles.ItemCode')
                ->where('sap_masterfiles.ItemCode', '!=', '')
                ->whereNotNull('sap_masterfiles.AltUOM')
                ->where('sap_masterfiles.AltUOM', '!=', '');

            // Add search conditions if search term is provided (keeping search functionality)
            if ($search) {
                $itemsQuery->where(function($query) use ($search) {
                    $query->where('sap_masterfiles.ItemCode', 'like', "%{$search}%")
                          ->orWhere('sap_masterfiles.ItemDescription', 'like', "%{$search}%");
                });
                $itemsQuery->limit(50); // Smaller limit for search results
            } else {
                $itemsQuery->orderBy('sap_masterfiles.ItemDescription')
                          ->limit(50); // Reduced limit like original
            }

            $items = $itemsQuery->get();

            $queryTime = (microtime(true) - $queryStartTime) * 1000; // Convert to milliseconds

            // Process items with better UOM fallback handling
            $processedItems = $items->map(function ($item) {
                // Create fallback description if ItemDescription is null
                $description = $item->ItemDescription;
                if (empty($description)) {
                    $description = "Product Item {$item->ItemCode}";
                    \Log::warning("getAvailableItems: DESCRIPTION WAS NULL/EMPTY for item {$item->ItemCode}, using fallback: {$description}");
                }

                $effectiveAltUom = $item->AltUOM; // AltUOM is guaranteed due to JOIN condition
                $stock = $item->stock_quantity; // Stock is guaranteed due to JOIN condition

                // Log specifically for item 916A2C
                if ($item->ItemCode === '916A2C') {
                    \Log::info("getAvailableItems: Processing 916A2C", [
                        'original_description' => $item->ItemDescription,
                        'final_description' => $description,
                        'base_uom' => $item->BaseUOM,
                        'alt_uom' => $item->AltUOM,
                        'effective_alt_uom' => $effectiveAltUom,
                        'stock' => $stock
                    ]);
                }

                return [
                    'id' => $item->id,
                    'item_code' => $item->ItemCode,
                    'description' => $description,
                    'uom' => $item->BaseUOM,
                    'alt_uom' => $effectiveAltUom, // Use fallback logic
                    'cost_per_quantity' => 1.0, // Default cost - removed expensive lookup
                    'stock' => $stock,
                    'is_available' => $stock > 0,
                ];
            });

            return response()->json([
                'items' => $processedItems,
                'query_time_ms' => round($queryTime, 2),
                'total_items' => $processedItems->count()
            ]);

        } catch (\Exception $e) {
            $errorMessage = 'getAvailableItems error for store ' . $sendingStoreId . ': ' . $e->getMessage();
            \Log::error($errorMessage);

            return response()->json([
                'error' => 'Failed to fetch items',
                'message' => 'Database query timeout or error. Please try again.',
                'items' => [] // Return empty array instead of error to prevent frontend breaking
            ], 500);
        }
    }

    /**
     * Get detailed information for a specific item including stock levels
     */
    public function getItemDetails(Request $request): JsonResponse
    {
        $request->validate([
            'itemCode' => 'required|string',
            'altUOM' => 'required|string',
            'sendingStoreId' => 'required|integer|exists:store_branches,id'
        ]);

        $itemCode = $request->input('itemCode');
        $altUOM = $request->input('altUOM');
        $sendingStoreId = $request->input('sendingStoreId');

        try {
            // Get the SAP masterfile item - try exact match first, then fallback to ItemCode only
            $item = SAPMasterfile::where('ItemCode', $itemCode)
                ->where('is_active', true)
                ->whereNotNull('ItemDescription')
                ->first();

            if (!$item) {
                \Log::info("Item not found for ItemCode: {$itemCode}");
                return response()->json([
                    'error' => 'Item not found'
                ], 404);
            }

            // Use AltUOM if available and matches request, otherwise fallback to BaseUOM
            $effectiveAltUom = (!empty($item->AltUOM) && $item->AltUOM === $altUOM) ? $item->AltUOM : $item->BaseUOM;

            \Log::info("Found item: {$itemCode} - AltUOM: {$item->AltUOM}, BaseUOM: {$item->BaseUOM} (effective: {$effectiveAltUom})");

            // Get stock information for this item at the sending store using optimized query
            $stock = ProductInventoryStock::where('store_branch_id', $sendingStoreId)
                ->where('product_inventory_id', $item->id)
                ->value('quantity') ?? 0;

            // Get cost information using optimized helper method
            $cost = $this->getItemCost($item->id);

            // Create fallback description if ItemDescription is null
            $description = $item->ItemDescription;
            if (empty($description)) {
                $description = "Product Item {$item->ItemCode}";
                \Log::warning("DESCRIPTION WAS NULL/EMPTY for item {$itemCode}, using fallback: {$description}");
            }

            $itemDetails = [
                'id' => $item->id,
                'item_code' => $item->ItemCode,
                'description' => $description,
                'uom' => $item->BaseUOM,
                'alt_uom' => $effectiveAltUom, // Use effective UOM with fallback logic
                'cost_per_quantity' => $cost,
                'stock' => $stock,
                'is_available' => $stock > 0,
            ];

            \Log::info("Returning itemDetails for {$itemCode}: ", [
                'original_description' => $item->ItemDescription,
                'final_description' => $description,
                'description_length' => strlen($description),
                'item_details' => $itemDetails
            ]);

            return response()->json([
                'item' => $itemDetails
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch item details: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get item cost separately to avoid complex JOIN performance issues
     */
    private function getItemCost(int $productId): float
    {
        try {
            // Optimized cost lookup with simplified query
            $cost = \App\Models\ProductInventoryCostHistory::where('product_inventory_id', $productId)
                ->where('start_date', '<=', now())
                ->where('end_date', '>=', now())
                ->orderBy('created_at', 'desc') // Get most recent cost
                ->value('cost');

            return $cost ?: 1.0;

        } catch (\Exception $e) {
            \Log::warning('getItemCost error for product ID ' . $productId . ': ' . $e->getMessage());
            return 1.0;
        }
    }

    /**
     * Get average cost for an item (helper method)
     */
    private function getAverageCost(string $itemCode): float
    {
        try {
            // Simplified cost calculation to avoid performance issues
            // In a real production environment, this should be implemented as:
            // - A cached cost lookup table
            // - Product cost integration
            // - SAP masterfile cost fields

            // Return a default cost for now - this prevents database timeouts
            return 1.0;

        } catch (\Exception $e) {
            \Log::warning('getAverageCost error for item ' . $itemCode . ': ' . $e->getMessage());
            return 1.0;
        }
    }

    /**
     * Get inventory stock data for specific items at a branch
     */
    public function getBranchInventory(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'required|integer|exists:store_branches,id',
            'item_codes' => 'required|string'
        ]);

        try {
            $branchId = $request->input('branch_id');
            $itemCodes = array_filter(explode(',', $request->input('item_codes')));

            \Log::info('Fetching branch inventory', [
                'branch_id' => $branchId,
                'item_codes' => $itemCodes
            ]);

            try {
                // Query ProductInventoryStock for the specified items and branch
                $stockData = ProductInventoryStock::with('sapMasterfile')
                    ->where('store_branch_id', $branchId)
                    ->whereHas('sapMasterfile', function($query) use ($itemCodes) {
                        $query->whereIn('ItemCode', $itemCodes);
                    })
                    ->get()
                    ->map(function($stock) {
                        return [
                            'item_code' => $stock->sapMasterfile->ItemCode,
                            'quantity' => floatval($stock->quantity)
                        ];
                    });

                \Log::info('Stock data retrieved', [
                    'items_count' => $stockData->count(),
                    'data' => $stockData->toArray()
                ]);

            } catch (\Exception $e) {
                \Log::error('Error in stock data query: ' . $e->getMessage(), [
                    'branch_id' => $branchId,
                    'item_codes' => $itemCodes,
                    'trace' => $e->getTraceAsString()
                ]);

                // Return empty results instead of failing
                $stockData = collect([]);
            }

            return response()->json([
                'items' => $stockData
            ]);

        } catch (\Exception $e) {
            \Log::error('Branch inventory fetch error: ' . $e->getMessage(), [
                'branch_id' => $request->input('branch_id'),
                'item_codes' => $request->input('item_codes'),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to fetch inventory data',
                'items' => []
            ], 500);
        }
    }

    /**
     * Approve an interco order
     */
    public function approve(Request $request, StoreOrder $interco): RedirectResponse
    {
        $validated = $request->validate([
            'remarks' => 'nullable|string|max:1000',
        ]);

        try {
            $interco->update([
                'interco_status' => 'approved',
                'approver_id' => Auth::id(),
                'approval_action_date' => now(),
                'remarks' => $validated['remarks'] ?? $interco->remarks,
                'updated_at' => now(),
            ]);

            return redirect()
                ->route('interco.show', $interco)
                ->with('success', 'Interco order approved successfully.');

        } catch (\Exception $e) {
            \Log::error('Failed to approve interco order: ' . $e->getMessage(), [
                'interco_id' => $interco->id,
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()
                ->with('error', 'Failed to approve interco order. Please try again.')
                ->withInput();
        }
    }

    /**
     * Disapprove an interco order
     */
    public function disapprove(Request $request, StoreOrder $interco): RedirectResponse
    {
        $validated = $request->validate([
            'remarks' => 'nullable|string|max:1000',
        ]);

        try {
            $interco->update([
                'interco_status' => 'disapproved',
                'approver_id' => Auth::id(),
                'approval_action_date' => now(),
                'remarks' => $validated['remarks'] ?? $interco->remarks,
                'updated_at' => now(),
            ]);

            return redirect()
                ->route('interco.show', $interco)
                ->with('success', 'Interco order disapproved successfully.');

        } catch (\Exception $e) {
            \Log::error('Failed to disapprove interco order: ' . $e->getMessage(), [
                'interco_id' => $interco->id,
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()
                ->with('error', 'Failed to disapprove interco order. Please try again.')
                ->withInput();
        }
    }

    /**
     * Commit an interco order (mark as transferred/completed)
     */
    public function commit(Request $request, StoreOrder $interco): RedirectResponse
    {
        $validated = $request->validate([
            'remarks' => 'nullable|string|max:1000',
        ]);

        try {
            $interco->update([
                'interco_status' => 'completed',
                'commiter_id' => Auth::id(),
                'commited_action_date' => now(),
                'remarks' => $validated['remarks'] ?? $interco->remarks,
                'updated_at' => now(),
            ]);

            return redirect()
                ->route('interco.show', $interco)
                ->with('success', 'Interco order committed successfully.');

        } catch (\Exception $e) {
            \Log::error('Failed to commit interco order: ' . $e->getMessage(), [
                'interco_id' => $interco->id,
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()
                ->with('error', 'Failed to commit interco order. Please try again.')
                ->withInput();
        }
    }

    }