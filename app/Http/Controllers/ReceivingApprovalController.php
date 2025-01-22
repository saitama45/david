<?php

namespace App\Http\Controllers;

use App\Enum\OrderStatus;
use App\Models\OrderedItemReceiveDate;
use App\Models\OrderItemRemark;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\StoreOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ReceivingApprovalController extends Controller
{
    public function index()
    {
        $search = request('search');

        $query = StoreOrder::query()->with([
            'supplier',
            'store_branch',
            'ordered_item_receive_dates' => function ($query) {
                $query->where('status', 'pending');
            },
            'image_attachments' => function ($query) {
                $query->where('is_approved', false);
            },
        ])
            ->whereHas('image_attachments', function ($query) {
                $query->where('is_approved', false);
            })
            ->orWhereHas('ordered_item_receive_dates', function ($query) {
                $query->where('status', 'pending');
            });

        $user = User::rolesAndAssignedBranches();

        if (!$user['isAdmin']) $query->whereIn('store_branch_id', $user['assignedBranches']);

        if ($search)
            $query->whereAny(['order_number'], 'like', "%$search%");


        $orders = $query->paginate(10)->withQueryString();

        return Inertia::render('ReceivingApproval/Index', [
            'orders' => $orders,
            'filters' => request()->only(['search'])
        ]);
    }

    public function show($id)
    {
        $order = StoreOrder::with([
            'image_attachments' => function ($query) {
                $query->where('is_approved', false);
            },
        ])->where('order_number', $id)->firstOrFail();
        $items = $order->ordered_item_receive_dates()->with('store_order_item.product_inventory')->where('status', 'pending')->get();
        $images = $order->image_attachments->map(function ($image) {
            return [
                'id' => $image->id,
                'image_url' => Storage::url($image->file_path),
            ];
        });
        return Inertia::render('ReceivingApproval/Show', [
            'order' => $order,
            'items' => $items,
            'images' => $images
        ]);
    }

    public function declineReceivedItem(Request $request)
    {
        $validated = $request->validate([
            'id' => ['required'],
            'remarks' => ['required']
        ]);

        $data = OrderedItemReceiveDate::with('store_order_item.product_inventory')->find($validated['id']);
        DB::beginTransaction();
        $data->update([
            'status' => 'declined',
            'remarks' => $validated['remarks']
        ]);
        DB::commit();

        return back();
    }

    public function approveReceivedItem(Request $request)
    {
        $validated = $request->validate([
            'id' => ['required'],
        ]);

        if (is_array($validated['id'])) {
            foreach ($validated['id'] as $id) {
                DB::beginTransaction();
                $data = OrderedItemReceiveDate::with('store_order_item.product_inventory')->find($id);
                $this->extracted($data);
                $data->save();
                $data->store_order_item->save();

                $this->getOrderStatus($data);

                DB::commit();
            }
        } else {
            DB::beginTransaction();
            $data = OrderedItemReceiveDate::with(['store_order_item.store_order.store_order_items', 'store_order_item.product_inventory'])->find($validated['id']);
            $this->extracted($data);
            $data->store_order_item->save();
            $data->save();
            $this->getOrderStatus($data);


            DB::commit();
        }


        return back();
    }

    public function extracted($data): void
    {
        $data->update(['status' => 'approved', 'approval_action_by' => Auth::user()->id, 'received_date' => Carbon::today()->toDateTimeString()]);
        $item = $data->store_order_item->product_inventory;

        $storeOrder = $data->store_order_item->store_order;


        $stock = ProductInventoryStock::where('product_inventory_id', $item->id)->where('store_branch_id', $storeOrder->store_branch_id);
        $stock->increment('quantity', $data->quantity_received);
        $stock->update(['recently_added' => $data->quantity_received]);

        ProductInventoryStockManager::create([
            'product_inventory_id' => $item->id,
            'store_branch_id' => $storeOrder->store_branch_id,
            'quantity' => $data->quantity_received,
            'action' => 'add_quantity',
            'remarks' => 'From newly received items. (Order Number: ' . $storeOrder->order_number . ')'
        ]);

        $data->store_order_item->quantity_received += $data->quantity_received;


        $item->save();
    }

    public function getOrderStatus($data)
    {
        $storeOrder = StoreOrder::with('store_order_items')->find($data->store_order_item->store_order_id);
        $orderedItems = $storeOrder->store_order_items;
        $storeOrder->order_status = OrderStatus::RECEIVED->value;
        foreach ($orderedItems as $itemOrdered) {
            if ($itemOrdered->quantity_commited > $itemOrdered->quantity_received) {
                $storeOrder->order_status = OrderStatus::INCOMPLETE->value;
            }
        }
        $storeOrder->save();
    }
}
