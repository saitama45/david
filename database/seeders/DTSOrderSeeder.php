<?php

namespace Database\Seeders;

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
        // Delivery schedule mapping (1 = Monday, 2 = Tuesday, etc.)
        $branchDeliverySchedules = [
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
        ];

        // Generate dates from September to October (excluding Sundays)
        $startDate = Carbon::create(2024, 9, 1);
        $endDate = Carbon::create(2024, 10, 31);

        foreach ($branchDeliverySchedules as $branchId => $deliveryDays) {
            $currentDate = $startDate->copy();

            while ($currentDate <= $endDate) {
                // Skip if Sunday (7) or not in delivery days
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

                    $quantity = random_int(5, 10);
                    $storeOrder->store_order_items()->create([
                        'product_inventory_id' => 498,
                        'quantity_ordered' => $quantity,
                        'quantity_approved' => $quantity,
                        'quantity_received' => $quantity,
                        'total_cost' => 109.65 * $quantity
                    ]);
                }

                $currentDate->addDay();
            }
        }
    }
}
