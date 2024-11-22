<?php

namespace Database\Seeders;

use App\Models\StoreBranch;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class StoreBranchSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $branches = [
            [
                'branch_code' => 'MAIN_HO',
                'name' => 'Head Office'
            ],
            [
                'branch_code' => 'NS',
                'name' => 'NO SO'
            ],
            [
                'branch_code' => 'DS',
                'name' => 'DROPSHIPPING'
            ],
            [
                'branch_code' => 'SMPAS',
                'name' => 'SM PASIG'
            ],
            [
                'branch_code' => 'SMFES',
                'name' => 'SM FESTIVAL'
            ],
            [
                'branch_code' => 'NNVIA',
                'name' => 'Arcovia'
            ],
            [
                'branch_code' => 'NNABA',
                'name' => 'Ayala Malls Manila Bay'
            ],
            [
                'branch_code' => 'NNFIL',
                'name' => 'Filinvest Super Mall'
            ],
            [
                'branch_code' => 'NNGWM',
                'name' => 'Gateway Mall 2'
            ],
            [
                'branch_code' => 'NNGLO',
                'name' => 'Glorietta 2'
            ],
            [
                'branch_code' => 'NNGFD',
                'name' => 'Greenfield'
            ],
            [
                'branch_code' => 'NNHSS',
                'name' => 'High Street South'
            ],
            [
                'branch_code' => 'NNKLT',
                'name' => 'KL Tower Serviced Residences'
            ],
            [
                'branch_code' => 'NNLGC',
                'name' => 'Laus Group Complex'
            ],
            [
                'branch_code' => 'NNOKA',
                'name' => 'Nono\'s Okada'
            ],
            [
                'branch_code' => 'NNUTC',
                'name' => 'NONO\'S UP TOWN Center'
            ],
            [
                'branch_code' => 'NNNUV',
                'name' => 'Nuvali'
            ],
            [
                'branch_code' => 'NNOPU',
                'name' => 'Opus'
            ],
            [
                'branch_code' => 'NNPDM',
                'name' => 'Podium Ortigas'
            ],
            [
                'branch_code' => 'NNRAO',
                'name' => 'Robinson\'s Antipolo'
            ],
            [
                'branch_code' => 'NNRWL',
                'name' => 'Rockwell'
            ],
            [
                'branch_code' => 'NNSMA',
                'name' => 'S Maison'
            ],
            [
                'branch_code' => 'NNBIC',
                'name' => 'SM Bicutan'
            ],
            [
                'branch_code' => 'NNSFV',
                'name' => 'SM Fairview Nono\'s'
            ],
            [
                'branch_code' => 'NNSGC',
                'name' => 'SM Grand Central'
            ],
            [
                'branch_code' => 'NNSSR',
                'name' => 'SM Santa Rosa'
            ],
            [
                'branch_code' => 'TBK-RWL',
                'name' => 'THE BLUE KITCHEN'
            ],
            [
                'branch_code' => 'NNTOL',
                'name' => 'The Outlets at Lipa'
            ],
            [
                'branch_code' => 'NN3CN',
                'name' => 'Three Central'
            ],
            [
                'branch_code' => 'NNUPM',
                'name' => 'Uptown Mall'
            ],
            [
                'branch_code' => 'NNVER',
                'name' => 'Vermosa'
            ],
            [
                'branch_code' => 'NNGAL',
                'name' => 'Robinson\'s Galleria'
            ],
        ];

        foreach($branches as $branch){
            StoreBranch::create($branch);
        }
    }
}
