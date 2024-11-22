<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $supplies = [
            [
                'supplier_code' => 'CS',
                'name' => 'Customer Service'
            ],
            [
                'supplier_code' => 'GSI-B',
                'name' => 'GSI OT-BAKERY'
            ],
            [
                'supplier_code' => 'GSI-P',
                'name' => 'GSI OT-PR'
            ],
            [
                'supplier_code' => 'PUL-O',
                'name' => 'PUL OT-DG'
            ],
            [
                'supplier_code' => 'DROPS',
                'name' => 'DROPSHIPPING'
            ],
        ];

        foreach ($supplies as $supplier) {
            Supplier::create($supplier);
        }
    }
}
