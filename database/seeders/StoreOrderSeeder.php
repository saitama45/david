<?php

namespace Database\Seeders;

use App\Models\StoreOrder;
use App\Models\ProductInventory;
use App\Models\StoreBranch;
use App\Models\Supplier;
use App\Models\User;
use App\Enums\OrderStatus;
use App\Enums\OrderRequestStatus;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class StoreOrderSeeder extends Seeder
{
    /**
     * Generate order number based on branch
     */
    private function getOrderNumber($branchId)
    {
        $branchCode = StoreBranch::select('branch_code')->findOrFail($branchId)->branch_code;
        $orderCount = StoreOrder::where('store_branch_id', $branchId)->count() + 1;

        while (true) {
            $orderNumber = str_pad($orderCount, 5, '0', STR_PAD_LEFT);
            $store_order_number = "$branchCode-$orderNumber";
            $result = StoreOrder::where('order_number', $store_order_number)->first();
            $orderCount++;
            if (!$result) break;
        }
        return $store_order_number;
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all()->pluck('id')->toArray();
        $branches = StoreBranch::all()->pluck('id')->toArray();
        $suppliers = Supplier::all()->pluck('id')->toArray();
        $products = ProductInventory::all()->pluck('id')->toArray();

        $startDate = Carbon::parse('2024-12-27');
        $endDate = Carbon::now();

        // Create orders for each day
        $currentDate = $startDate->copy();
        while ($currentDate <= $endDate) {
            // Create 20 orders for this day
            for ($i = 0; $i < 20; $i++) {
                $branchId = $branches[array_rand($branches)];

                $order = StoreOrder::create([
                    'encoder_id' => $users[array_rand($users)],
                    'supplier_id' => $suppliers[array_rand($suppliers)],
                    'store_branch_id' => $branchId,
                    'order_number' => $this->getOrderNumber($branchId),
                    'order_date' => $currentDate->format('Y-m-d'),
                    'order_status' => 'pending',
                    'order_request_status' => 'approved',
                ]);

                // Create 2-5 order items for each order
                $numberOfItems = rand(2, 5);
                for ($j = 0; $j < $numberOfItems; $j++) {
                    $quantity = rand(1, 20);
                    $unitCost = rand(100, 1000);

                    $order->store_order_items()->create([
                        'product_inventory_id' => $products[array_rand($products)],
                        'quantity_ordered' => $quantity,
                        'total_cost' => $quantity * $unitCost,
                    ]);
                }
            }

            // Move to next day
            $currentDate->addDay();
        }
    }
}
