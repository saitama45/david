<?php

namespace Tests\Unit;

use App\Models\SupplierItems;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MassOrdersItemsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->withoutMiddleware();

        Schema::create('supplier_items', function (Blueprint $table) {
            $table->id();
            $table->string('ItemCode');
            $table->string('item_name')->default('');
            $table->string('SupplierCode');
            $table->string('category')->default('');
            $table->string('brand')->default('');
            $table->string('classification')->default('');
            $table->string('packaging_config')->default('');
            $table->decimal('config', 10, 2)->default(0);
            $table->string('uom')->default('');
            $table->decimal('cost', 10, 2)->default(0);
            $table->decimal('srp', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function test_it_returns_consolidated_active_item_options_for_cpo_mass_orders(): void
    {
        $this->actingAs(new User(['id' => 1]));

        $this->createSupplierItem('B001', 'Bakery Item', 'GSI-B', 'BOX', true, 2);
        $this->createSupplierItem('P001', 'PR Item', 'GSI-P', 'PCS', true, 1);
        $this->createSupplierItem('PL001', 'Pulled Item', 'PUL-O', 'KG');
        $this->createSupplierItem('D001', 'Drops Item', 'DROPS', 'BAG');
        $this->createSupplierItem('C001', 'CPO Item', 'CPO', 'CASE');
        $this->createSupplierItem('B001', 'Duplicate Bakery Item', 'GSI-P', 'BOX');
        $this->createSupplierItem('INACTIVE', 'Inactive Item', 'GSI-B', 'BOX', false);
        $this->createSupplierItem('OTHER', 'Other Supplier Item', 'OTHER', 'BOX');

        $response = $this->getJson(route('mass-orders.items', ['supplier_code' => 'CPO']));

        $response->assertOk()
            ->assertJsonPath('items.0.value', 'P001')
            ->assertJsonFragment([
                'value' => 'B001',
                'label' => 'Bakery Item (B001) BOX',
                'supplierCode' => 'GSI-B',
            ]);

        $itemCodes = collect($response->json('items'))->pluck('value');

        $this->assertContains('B001', $itemCodes);
        $this->assertContains('P001', $itemCodes);
        $this->assertContains('PL001', $itemCodes);
        $this->assertContains('D001', $itemCodes);
        $this->assertContains('C001', $itemCodes);
        $this->assertNotContains('INACTIVE', $itemCodes);
        $this->assertNotContains('OTHER', $itemCodes);
        $this->assertSame([], $itemCodes->duplicates()->all());
    }

    public function test_it_returns_active_item_options_for_a_single_non_cpo_supplier(): void
    {
        $this->actingAs(new User(['id' => 1]));

        $this->createSupplierItem('B001', 'Bakery Item', 'GSI-B', 'BOX');
        $this->createSupplierItem('B002', 'Inactive Bakery Item', 'GSI-B', 'BOX', false);
        $this->createSupplierItem('P001', 'PR Item', 'GSI-P', 'PCS');

        $response = $this->getJson(route('mass-orders.items', ['supplier_code' => 'GSI-B']));

        $response->assertOk()
            ->assertJson([
                'items' => [
                    [
                        'value' => 'B001',
                        'label' => 'Bakery Item (B001) BOX',
                        'supplierCode' => 'GSI-B',
                    ],
                ],
            ]);
    }

    private function createSupplierItem(
        string $itemCode,
        string $itemName,
        string $supplierCode,
        string $uom,
        bool $isActive = true,
        int $sortOrder = 0
    ): void {
        SupplierItems::create([
            'ItemCode' => $itemCode,
            'item_name' => $itemName,
            'SupplierCode' => $supplierCode,
            'uom' => $uom,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
        ]);
    }
}
