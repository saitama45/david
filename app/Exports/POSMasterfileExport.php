<?php

namespace App\Exports;

use App\Models\POSMasterfile; // Make sure to use your actual SAP Masterfile model
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping; // Used for custom column mapping

class POSMasterfileExport implements FromQuery, WithHeadings, WithMapping
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
        $query = POSMasterfile::query(); // Start with your POSMasterfile model

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
            'Category',
            'SubCategory',
            'SRP',
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
            $item->ItemCode,
            $item->ItemDescription,
            $item->Category,
            $item->SubCategory,
            $item->SRP,
            $item->is_active ? 'Yes' : 'No', // Convert boolean to readable string
            $item->created_at,
            $item->updated_at,
        ];
    }
}