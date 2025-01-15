<?php

namespace App\Imports;

use App\Models\Menu;
use App\Models\UsageRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class StoreTransactionsImport implements ToCollection, WithHeadingRow
{
    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            $storeTransaction = UsageRecord::create([
                'store_branch_id' => $row['store_branch_id'],
                'encoder_id' => Auth::id(),
                'order_number' => $row['order_number'],
                'transaction_period' => $row['transaction_period'],
                'transaction_date' => Carbon::parse($row['transaction_date'])->format('Y-m-d'),
                'cashier_id' => $row['cashier_id'],
                'order_type' => $row['order_type'],
                'sub_total' => $row['sub_total'],
                'total_amount' => $row['total_amount'],
                'tax_amount' => $row['tax_amount'] ?? 0,
                'payment_type' => $row['payment_type'],
                'discount_amount' => $row['discount_amount'] ?? 0,
                'discount_type' => $row['discount_type'],
                'service_charge' => $row['service_charge'] ?? 0,
                'remarks' => $row['remarks'],
            ]);

            $ordersList = explode(',', $row['orders']);


            foreach ($ordersList as $order) {
                $orderInfo = trim($order);

                list($menuCode, $quantity) = array_pad(
                    explode(':', $orderInfo),
                    2,
                    null
                );

                $menuCode = trim($menuCode);
                $quantity = trim($quantity);
                // $ingredient = Menu::findOrFail($menuCode);

                $storeTransaction->usage_record_items()->create([
                    'menu_id' => $menuCode,
                    'quantity' => $quantity
                ]);
            }
        }
    }
}
