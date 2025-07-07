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

    public function __construct($search = null, $filter = null)
    {
        $this->search = $search;
        $this->filter = $filter;
    }

    public function query()
    {
        $query = SAPMasterfile::query(); // Start with your SAPMasterfile model

        // Apply search logic
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('ItemNo', 'like', '%' . $this->search . '%')
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
            'Item No',
            'Item Description',
            'Base UOM',
            'Base QTY',
            'Alternate UOM',
            'Alternate QTY',
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
            $item->ItemNo,
            $item->ItemDescription,
            $item->BaseUOM,
            $item->BaseQty,
            $item->AltUOM,
            $item->AltQty,
            $item->is_active ? 'Yes' : 'No', // Convert boolean to readable string
            $item->created_at,
            $item->updated_at,
        ];
    }
}