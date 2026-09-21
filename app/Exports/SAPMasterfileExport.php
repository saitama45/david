<?php

namespace App\Exports;

use App\Models\SAPMasterfile; // Make sure to use your actual SAP Masterfile model
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping; // Used for custom column mapping

class SAPMasterfileExport implements FromQuery, WithHeadings, WithMapping
{
    protected $search;
    protected $filter;
    protected $type;

    public function __construct($search = null, $filter = null, $type = null)
    {
        $this->search = $search;
        $this->filter = $filter;
        $this->type = $type;
    }

    public function query()
    {
        $query = SAPMasterfile::query()->withItemType()->whereItemType($this->type);

        // Apply search logic
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('ItemCode', 'like', '%' . $this->search . '%')
                  ->orWhere('ItemDescription', 'like', '%' . $this->search . '%');
                // Add more fields here if you want to search by them
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
            'Item Code',
            'Item Description',
            'Base UOM',
            'Base QTY',
            'Alternate UOM',
            'Alternate QTY',
            'Active',
            'Item Type',
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
            $item->ItemCode,
            $item->ItemDescription,
            $item->BaseUOM,
            $item->BaseQty,
            $item->AltUOM,
            $item->AltQty,
            $item->is_active ? 'Yes' : 'No', // Convert boolean to readable string
            $item->sap_item_type_name ?? '',
            $item->created_at,
            $item->updated_at,
        ];
    }
}