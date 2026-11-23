<?php

use App\Imports\MonthEndCountTemplatesImport;
use App\Models\Entity;
use App\Models\MonthEndCountTemplate;
use App\Models\SAPMasterfile;
use App\Models\User;
use App\Support\EntityContext;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

/** Imports a one-line template list whose classification column is headed $heading. */
function importMecTemplateList(string $heading): MonthEndCountTemplate
{
    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);
    test()->actingAs(User::factory()->create());
    SAPMasterfile::create(['ItemCode' => 'RM-SUGAR', 'ItemDescription' => 'Sugar', 'AltQty' => 1, 'BaseQty' => 1, 'AltUOM' => 'Case', 'BaseUOM' => 'Case', 'is_active' => true]);

    $sheet = new class($heading) implements FromArray, WithHeadings
    {
        public function __construct(private string $heading) {}

        public function headings(): array
        {
            return ['Item Code', 'Item Name', $this->heading, 'Area', 'Category 2', 'Packaging', 'Conversion', 'Bulk UOM', 'Loose UOM'];
        }

        public function array(): array
        {
            return [['RM-SUGAR', 'Sugar', 'DRY GOODS', 'Pantry', 'Baking', '1 Case', 1000, 'Case', 'Gm']];
        }
    };

    $path = tempnam(sys_get_temp_dir(), 'mect').'.xlsx';
    file_put_contents($path, Excel::raw($sheet, \Maatwebsite\Excel\Excel::XLSX));

    try {
        Excel::import(new MonthEndCountTemplatesImport, $path);
    } finally {
        @unlink($path);
    }

    return MonthEndCountTemplate::where('item_code', 'RM-SUGAR')->sole();
}

it('saves the Classification column of an uploaded template list', function () {
    expect(importMecTemplateList('Classification')->category)->toBe('DRY GOODS');
});

it('still reads a template list whose column is headed Category 1', function () {
    expect(importMecTemplateList('Category 1')->category)->toBe('DRY GOODS');
});
