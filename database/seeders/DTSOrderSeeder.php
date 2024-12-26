<?php

namespace Database\Seeders;

use App\Models\ProductInventory;
use App\Models\StoreOrder;
use App\Models\StoreBranch;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DTSOrderSeeder extends Seeder
{
    /**
     * Generate unique order number based on branch code
     */
    private function generateOrderNumber($branchId): string
    {
        $branchCode = StoreBranch::select('branch_code')->findOrFail($branchId)->branch_code;
        $orderCount = StoreOrder::where('store_branch_id', $branchId)->count() + 1;

        while (true) {
            $orderNumber = str_pad($orderCount, 5, '0', STR_PAD_LEFT);
            $storeOrderNumber = "$branchCode-$orderNumber";

            $exists = StoreOrder::where('order_number', $storeOrderNumber)->exists();
            if (!$exists) {
                break;
            }

            $orderCount++;
        }

        return $storeOrderNumber;
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $iceCreamBranchDeliverySchedules = [
            11 => [1, 3, 5],    // GREENFIELD - Mon, Wed, Fri
            7 => [1, 4],        // AYALA MALLS MANILA BAY - Mon, Thu
            15 => [1, 4],       // OKADA - Mon, Thu
            8 => [1, 4],        // FIL - Mon, Thu
            31 => [2, 5],       // Vermosa - Tue, Fri
            17 => [3, 6],       // Nuvali - Wed, Sat
            22 => [1, 4],       // SM MAISON - Mon, Thu
            20 => [1, 4],       // RAO ANTIPOLO - Mon, Thu
            24 => [1, 4],       // SFW SJDM - Mon, Thu
            25 => [1, 4],       // SGC CAMANAVA - Mon, Thu
            29 => [2, 5],       // 3CN MAKATI - Tue, Fri
            21 => [2, 5],       // RWL MAKATI - Tue, Fri
            10 => [2, 5],       // GLO MAKATI - Tue, Fri
            13 => [2, 5],       // KLT MAKATI - Tue, Fri
            12 => [2, 5],       // HSS BGC - Tue, Fri
            30 => [2, 5],       // UPM BGC - Tue, Fri
            23 => [2, 6],       // BIC SM BICUTAN - Tue, Sat
            26 => [3, 6],       // SSR STA ROSA - Wed, Sat
            6 => [3, 6],        // VIA CAVITE - Wed, Sat
            19 => [3, 6],       // PDM ORTIGAS - Wed, Sat
            9 => [3, 6],        // GWM QC - Wed, Sat
            28 => [3, 6],       // TOL NORTH - Wed, Sat
            14 => [4],          // TOL NORTH - Thu only
            16 => [3, 6]
        ];

        $salmonBranchDeliverySchedules = [
            21 => [1, 3, 5],    // Rockwell - Mon, Wed, Fri
            22 => [2, 4, 6],    // SM Maison - Tue, Thu, Sat
            23 => [4, 6],       // SM Bicutan - Thu, Sat
            14 => [2],          // Laus Group Complex - Tue
            28 => [3],          // The Outlets at Lipa - Wed
            30 => [1, 3, 5],    // Uptown Mall - Mon, Wed, Fri
            19 => [1, 3, 5],    // Podium Ortigas - Mon, Wed, Fri
            10 => [1, 3, 5],    // Glorietta 2 - Mon, Wed, Fri
            29 => [1, 3, 5],    // Three Central - Mon, Wed, Fri
            12 => [1, 3, 5],    // High Street South - Mon, Wed, Fri
            13 => [1, 3, 5],    // KL Tower Serviced Residences - Mon, Wed, Fri
            20 => [1, 3, 5],    // Robinson's Antipolo - Mon, Wed, Fri
            6 => [1, 3, 5],     // Robinson's Antipolo - Mon, Wed, Fri
            11 => [1, 3, 5],    // Greenfield - Mon, Wed, Fri
            16 => [2, 4, 6],    // NONO'S UP TOWN Center - Tue, Thu, Sat
            7 => [2, 4, 6],     // NONO'S UP TOWN Center - Tue, Thu, Sat
            8 => [2, 4, 6],     // Filinvest Super Mall - Tue, Thu, Sat
            17 => [2, 4, 6],    // Nuvali - Tue, Thu, Sat
            15 => [2, 4, 6],    // Nono's Okada - Tue, Thu, Sat
            26 => [2, 4, 6],    // SM Santa Rosa - Tue, Thu, Sat
            24 => [2, 4, 6],    // SM Fairview Nono's - Tue, Thu, Sat
            25 => [2, 4, 6],    // SM Grand Central - Tue, Thu, Sat
            9 => [2, 4, 6],     // Gateway Mall 2 - Tue, Thu, Sat
            31 => [2, 4, 6],    // Vermosa - Tue, Thu, Sat
        ];

        $nAndSFruitsAndVegetableBranchDeliverySchedules = [
            8 => [1, 3, 5],    // Filinvest Super Mall - Mon, Wed, Fri
            17 => [1, 3, 5],   // Nuvali - Mon, Wed, Fri
            26 => [1, 3, 5],   // SM Santa Rosa - Mon, Wed, Fri
            28 => [1, 3, 5],   // The Outlets at Lipa - Mon, Wed, Fri
            31 => [1, 3, 5],   // Vermosa - Mon, Wed, Fri
        ];

        $fruitsAndVegetablesList =  ProductInventory::with('store_order_items')
            ->where('inventory_category_id', 6)
            ->get()
            ->pluck('id', 'cost');

        $startDate = Carbon::create(2024, 11, 25);
        $endDate = Carbon::create(2024, 12, 28);

        $iceCreamId = ProductInventory::where('inventory_code', '359A2A')->first()->id;
        $salmon = ProductInventory::where('inventory_code', '269A2A')->first();

        // foreach ($iceCreamBranchDeliverySchedules as $branchId => $deliveryDays) {
        //     $currentDate = $startDate->copy();

        //     while ($currentDate <= $endDate) {
        //         if ($currentDate->dayOfWeek !== 0 && in_array($currentDate->dayOfWeek, $deliveryDays)) {
        //             $storeOrder = StoreOrder::create([
        //                 'encoder_id' => 1,
        //                 'supplier_id' => 5,
        //                 'store_branch_id' => $branchId,
        //                 'approver_id' => 1,
        //                 'order_number' => $this->generateOrderNumber($branchId),
        //                 'order_date' => $currentDate->format('Y-m-d'),
        //                 'order_status' => 'received',
        //                 'order_request_status' => 'approved',
        //                 'type' => 'dts',
        //             ]);

        //             $quantity = random_int(5, 10);
        //             $storeOrder->store_order_items()->create([
        //                 'product_inventory_id' => $iceCreamId,
        //                 'quantity_ordered' => $quantity,
        //                 'quantity_approved' => $quantity,
        //                 'quantity_received' => $quantity,
        //                 'total_cost' => 109.65 * $quantity
        //             ]);
        //         }

        //         $currentDate->addDay();
        //     }
        // }

        // foreach ($salmonBranchDeliverySchedules as $branchId => $deliveryDays) {
        //     $currentDate = $startDate->copy();

        //     while ($currentDate <= $endDate) {
        //         if ($currentDate->dayOfWeek !== 0 && in_array($currentDate->dayOfWeek, $deliveryDays)) {
        //             $storeOrder = StoreOrder::create([
        //                 'encoder_id' => 1,
        //                 'supplier_id' => 5,
        //                 'store_branch_id' => $branchId,
        //                 'approver_id' => 1,
        //                 'order_number' => $this->generateOrderNumber($branchId),
        //                 'order_date' => $currentDate->format('Y-m-d'),
        //                 'order_status' => 'received',
        //                 'order_request_status' => 'approved',
        //                 'type' => 'dts',
        //             ]);

        //             $quantity = random_int(5, 10);
        //             $storeOrder->store_order_items()->create([
        //                 'product_inventory_id' => $salmon->id,
        //                 'quantity_ordered' => $quantity,
        //                 'quantity_approved' => $quantity,
        //                 'quantity_received' => $quantity,
        //                 'total_cost' => $salmon->cost * $quantity
        //             ]);
        //         }

        //         $currentDate->addDay();
        //     }
        // }

        foreach ($nAndSFruitsAndVegetableBranchDeliverySchedules as $branchId => $deliveryDays) {
            $currentDate = $startDate->copy();

            while ($currentDate <= $endDate) {
                if ($currentDate->dayOfWeek !== 0 && in_array($currentDate->dayOfWeek, $deliveryDays)) {
                    $storeOrder = StoreOrder::create([
                        'encoder_id' => 1,
                        'supplier_id' => 5,
                        'store_branch_id' => $branchId,
                        'approver_id' => 1,
                        'order_number' => $this->generateOrderNumber($branchId),
                        'order_date' => $currentDate->format('Y-m-d'),
                        'order_status' => 'received',
                        'order_request_status' => 'approved',
                        'type' => 'dts',
                    ]);
                    for ($i = 0; $i < 5; $i++) {
                        $randomItem = $fruitsAndVegetablesList->random();
                        $productInventoryId = $randomItem;
                        $productCost = $fruitsAndVegetablesList->search($randomItem);

                        $quantity = random_int(1, 10);

                        $storeOrder->store_order_items()->create([
                            'product_inventory_id' => $productInventoryId,
                            'quantity_ordered' => $quantity,
                            'quantity_approved' => $quantity,
                            'quantity_received' => $quantity,
                            'total_cost' => $productCost * $quantity
                        ]);
                    }
                }
                $currentDate->addDay();
            }
        }
    }
}
