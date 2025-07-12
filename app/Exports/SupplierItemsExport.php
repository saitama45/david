<?php

namespace App\Exports;

use App\Models\SupplierItems; // Make sure to use your actual SAP Masterfile model
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping; // Used for custom column mapping

class SupplierItemsExport implements FromQuery, WithHeadings, WithMapping
{
    protected $search;
    protected $filter;

    public function __construct($search = null, $filter = null)
    {
        $this->search = $search;
        $this->filter = $filter;
    }

    public function query()
    {
        $query = SupplierItems::query(); // Start with your SAPMasterfile model

        // Apply search logic
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('ItemCode', 'like', '%' . $this->search . '%')
                  ->orWhere('SupplierCode', 'like', '%' . $this->search . '%')
                  ->orWhere('category', 'like', '%' . $this->search . '%')
                  ->orWhere('brand', 'like', '%' . $this->search . '%')
                  ->orWhere('classification', 'like', '%' . $this->search . '%')
                  ->orWhere('packaging_config', 'like', '%' . $this->search . '%')
                  ->orWhere('uom', 'like', '%' . $this->search . '%');
            });
        }

        // Apply filter logic
        if ($this->filter && $this->filter !== 'all') {
            if ($this->filter === 'is_active') {
                $query->where('is_active', true);
            } elseif ($this->filter === 'inactive') {
                $query->where('is_active', false);
            }
            // Add other filter conditions if you have more filters (e.g., by category, etc.)
        }

        return $query;
    }

    public function headings(): array
    {
        // Define your exact column headers for the Excel file
        return [
            'ID',
            'Item Code',        // Renamed from 'Item No'
            'Supplier Code',
            'Category',         // New
            'Brand',            // New
            'Classification',   // New
            'Packaging Config', // New
            'UOM',              // New
            'Cost',             // New
            'SRP',              // New
            'Active',
            'Created At',
            'Updated At',
        ];
    }

    /**
     * @param mixed $item
     * @return array
     */
    public function map($item): array
    {
        // Map the model attributes to the array that will be a row in Excel
        return [
            $item->id,
            $item->ItemCode,        // Renamed from ItemNo
            $item->SupplierCode,
            $item->category,
            $item->brand,
            $item->classification,
            $item->packaging_config,
            $item->uom,
            $item->cost,            // Will be formatted by Excel based on its type
            $item->srp,             // Will be formatted by Excel based on its type
            $item->is_active ? 'Yes' : 'No', // Convert boolean to readable string
            $item->created_at,
            $item->updated_at,
        ];
    }
}