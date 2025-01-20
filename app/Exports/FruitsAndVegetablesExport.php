<?php

namespace App\Exports;

use App\Models\ProductInventory;
use App\Models\StoreOrder;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class FruitsAndVegetablesExport implements FromCollection, WithHeadings
{
    protected $search;
    protected $branchId;
    protected $startDate;
    protected $dates;
    protected $storeOrders;

    public function __construct($search, $branchId, $startDate)
    {
        $this->search = $search;
        $this->branchId = $branchId;
        $this->startDate = $startDate ? Carbon::parse($startDate) : Carbon::now()->startOfWeek();
        $this->initializeDates();
        $this->initializeStoreOrders();
    }

    private function initializeDates()
    {
        $this->dates = [
            'monday' => $this->startDate->toDateString(),
            'tuesday' => $this->startDate->copy()->addDays(1)->toDateString(),
            'wednesday' => $this->startDate->copy()->addDays(2)->toDateString(),
            'thursday' => $this->startDate->copy()->addDays(3)->toDateString(),
            'friday' => $this->startDate->copy()->addDays(4)->toDateString(),
            'saturday' => $this->startDate->copy()->addDays(5)->toDateString(),
        ];
    }

    private function initializeStoreOrders()
    {
        $this->storeOrders = StoreOrder::with(['store_order_items.product_inventory'])
            ->where('variant', 'fruits and vegetables')
            ->whereBetween('order_date', [reset($this->dates), end($this->dates)])
            ->whereHas('store_order_items.product_inventory', function ($query) {
                $query->where('inventory_category_id', 6);
            });

        if ($this->branchId) {
            $this->storeOrders->whereIn('store_branch_id', $this->branchId);
        }

        $this->storeOrders = $this->storeOrders->get();
    }

    public function headings(): array
    {
        return [
            'Code',
            'Item',
            'Monday',
            'Tuesday',
            'Wednesday',
            'Thursday',
            'Friday',
            'Saturday'
        ];
    }

    public function collection()
    {
        $query = ProductInventory::query();

        if ($this->search) {
            $query->whereAny(['name', 'inventory_code'], 'like', "%{$this->search}%");
        }

        return $query->where('inventory_category_id', 6)
            ->get()
            ->map(function ($product) {
                $quantityByDay = collect($this->dates)->mapWithKeys(function ($date, $dayName) use ($product) {
                    $quantity = $this->storeOrders
                        ->where('order_date', $date)
                        ->flatMap(function ($order) {
                            return $order->store_order_items;
                        })
                        ->where('product_inventory_id', $product->id)
                        ->sum('quantity_ordered');

                    return [$dayName => $quantity ?: 0]; // Convert null or empty to 0
                });


                return [
                    'inventory_code' => $product->inventory_code,
                    'name' => $product->name,
                    'monday' => $quantityByDay['monday'] > 0 ? $quantityByDay['monday'] : "0",
                    'tuesday' => $quantityByDay['tuesday'] > 0 ? $quantityByDay['tuesday'] : "0",
                    'wednesday' => $quantityByDay['wednesday'] > 0 ? $quantityByDay['wednesday'] : "0",
                    'thursday' => $quantityByDay['thursday'] > 0 ? $quantityByDay['thursday'] : "0",
                    'friday' => $quantityByDay['friday'] > 0 ? $quantityByDay['friday'] : "0",
                    'saturday' => $quantityByDay['saturday'] > 0 ? $quantityByDay['saturday'] : "0"
                ];
            });
    }
}
