<?php

namespace App\Exports;

use App\Models\POSMasterfileBOM;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Illuminate\Support\Facades\Log; // Import Log for debugging if needed

class POSMasterfileBOMExport implements FromQuery, WithHeadings, WithMapping, WithStyles
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
        $query = POSMasterfileBOM::query();

        // Apply search logic
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('POSCode', 'like', '%' . $this->search . '%')
                  ->orWhere('POSDescription', 'like', '%' . $this->search . '%')
                  ->orWhere('Assembly', 'like', '%' . $this->search . '%')
                  ->orWhere('ItemCode', 'like', '%' . $this->search . '%')
                  ->orWhere('ItemDescription', 'like', '%' . $this->search . '%')
                  ->orWhere('RecipeUOM', 'like', '%' . $this->search . '%')
                  ->orWhere('BOMUOM', 'like', '%' . $this->search . '%');
            });
        }

        // Apply filter logic (assuming 'is_active' column exists in POSMasterfileBOM if needed)
        // Note: POSMasterfileBOM model currently doesn't have 'is_active'. If you need this,
        // you'll have to add it to the migration and model first.
        // For now, I'll comment out the filter logic as it's not directly supported by the model schema.
        /*
        if ($this->filter && $this->filter !== 'all') {
            if ($this->filter === 'is_active') {
                $query->where('is_active', true);
            } elseif ($this->filter === 'inactive') {
                $query->where('is_active', false);
            }
        }
        */

        return $query->latest(); // Order by latest records
    }

    public function headings(): array
    {
        return [
            'POS Code',
            'POS Description',
            'Assembly',
            'Item Code',
            'Item Description',
            'Rec Percent',
            'Recipe Qty',
            'Recipe UOM',
            'BOM Qty',
            'BOM UOM',
            'Unit Cost',
            'Total Cost',
            // Removed 'Created By',
            // Removed 'Updated By',
        ];
    }

    /**
     * @param mixed $bom
     * @return array
     */
    public function map($bom): array
    {
        // Removed eager loading of creator and updater as they are no longer needed for export display
        // $creatorName = $bom->creator->full_name ?? ($bom->created_by ? 'ID: ' . $bom->created_by : null);
        // $updaterName = $bom->updater->full_name ?? ($bom->updated_by ? 'ID: ' . $bom->updated_by : null);

        return [
            $bom->POSCode,
            $bom->POSDescription,
            $bom->Assembly,
            $bom->ItemCode,
            $bom->ItemDescription,
            // Format RecPercent as a percentage, rounded to 2 decimal places
            number_format($bom->RecPercent * 100, 2) . '%', 
            $bom->RecipeQty,
            $bom->RecipeUOM,
            $bom->BOMQty,
            $bom->BOMUOM,
            $bom->UnitCost,
            $bom->TotalCost,
            // Removed $creatorName,
            // Removed $updaterName,
        ];
    }

    /**
     * Apply styles to the worksheet.
     *
     * @param Worksheet $sheet
     */
    public function styles(Worksheet $sheet)
    {
        // Apply style to the first row (headers)
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => '00008B', // Dark Blue
                ],
            ],
            'font' => [
                'bold' => true,
                'color' => [
                    'rgb' => 'FFFFFF', // White text
                ],
            ],
        ]);
    }
}
