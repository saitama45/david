<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\SAPMasterfile;

class SAPMasterfilesSeeders extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
       SAPMasterfile::create([
        'ItemNo' => 'TEST',
        'ItemDescription' => 'Item for test only',
        'AltQty' => 1,
        'BaseQty' => 1,
        'AltUOM' => 'AUOM',
        'BaseUOM' => 'BUOM',
       ]);
    }

}
