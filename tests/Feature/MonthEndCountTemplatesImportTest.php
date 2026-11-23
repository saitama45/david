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

/** TC states a pack only as a conversion: 250 Gm = 1 Bag beside Gm/Gm, with no Bag / Bag row. */
function tcBagItemFixture(): void
{
    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);
    test()->actingAs(User::factory()->create());

    if (\Illuminate\Support\Facades\DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        \Illuminate\Support\Facades\DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    SAPMasterfile::create(['ItemCode' => '725A2H', 'ItemDescription' => 'Beans 250g', 'AltQty' => 250, 'AltUOM' => 'Gm', 'BaseQty' => 1, 'BaseUOM' => 'Bag', 'is_active' => true]);
    SAPMasterfile::create(['ItemCode' => '725A2H', 'ItemDescription' => 'Beans 250g', 'AltQty' => 1, 'AltUOM' => 'Gm', 'BaseQty' => 1, 'BaseUOM' => 'Gm', 'is_active' => true]);
}

/** Writes $rows under $headings to a temporary workbook and runs $import on it. */
function runImportOn(object $import, array $headings, array $rows): object
{
    $sheet = new class($headings, $rows) implements FromArray, WithHeadings
    {
        public function __construct(private array $headings, private array $rows) {}

        public function headings(): array
        {
            return $this->headings;
        }

        public function array(): array
        {
            return $this->rows;
        }
    };

    $path = tempnam(sys_get_temp_dir(), 'mect').'.xlsx';
    file_put_contents($path, Excel::raw($sheet, \Maatwebsite\Excel\Excel::XLSX));

    try {
        Excel::import($import, $path);
    } finally {
        @unlink($path);
    }

    return $import;
}

it('imports a Bulk UOM SAP gives only as a BaseUOM, and names the reason of a row it skips', function () {
    tcBagItemFixture();
    $headings = ['Item Code', 'Item Name', 'Classification', 'Area', 'Category 2', 'Packaging', 'Conversion', 'Bulk UOM', 'Loose UOM'];
    $rows = [
        ['725A2H', 'Beans 250g', 'BEANS & POWDER', 'CABINET', 'RETAIL', '250 Gm per Bag', 250, 'Bag', 'Gm'],
        ['725A2H', 'Beans 250g', 'BEANS & POWDER', 'CABINET', 'RETAIL', '1 Box', 1, 'Box', 'Gm'],
    ];

    $first = runImportOn(new MonthEndCountTemplatesImport, $headings, $rows);

    expect($first->getCreatedCount())->toBe(1)
        ->and(MonthEndCountTemplate::where('item_code', '725A2H')->sole()->only(['uom', 'loose_uom']))->toBe(['uom' => 'Bag', 'loose_uom' => 'Gm'])
        ->and($first->getSkippedRows())->toHaveCount(1)
        ->and($first->getSkippedRows()[0])->toMatchArray(['row_number' => 3, 'uom' => 'Box', 'reason' => "Item Code '725A2H' has no unit 'Box' in the SAP Masterlist."]);

    // The same list again: the Bag row is on the list as it is - unchanged, not skipped.
    $again = runImportOn(new MonthEndCountTemplatesImport, $headings, [$rows[0]]);

    expect($again->getCreatedCount())->toBe(0)
        ->and($again->getUnchangedCount())->toBe(1)
        ->and($again->getSkippedRows())->toBe([]);
});

it('uploads a store count whose Bulk UOM SAP gives only as a BaseUOM, keeping the count in that unit', function () {
    tcBagItemFixture();
    $user = User::factory()->create();
    test()->actingAs($user);
    $store = \App\Models\StoreBranch::create(['branch_code' => 'TST', 'brand_code' => 'TST', 'name' => 'Test Store', 'store_status' => 'Active', 'is_active' => 1]);
    $schedule = \App\Models\MonthEndSchedule::create(['year' => 2026, 'month' => 10, 'calculated_date' => '2026-10-31', 'created_by' => $user->id]);

    runImportOn(
        new \App\Imports\MonthEndCountImport($store->id, $schedule->id),
        ['Item Code', 'Item Name', 'Conversion', 'Bulk UOM', 'Loose UOM', 'Bulk Qty', 'Loose Qty'],
        [['725A2H', 'Beans 250g', 250, 'Bag', 'Gm', 3, 125]]
    );

    $line = \App\Models\MonthEndCountItem::where('month_end_schedule_id', $schedule->id)->sole();

    expect($line->uom)->toBe('Bag')
        ->and((float) $line->total_qty)->toEqualWithDelta(3.5, 1e-9)
        ->and(SAPMasterfile::find($line->sap_masterfile_id)->ItemCode)->toBe('725A2H');
});
