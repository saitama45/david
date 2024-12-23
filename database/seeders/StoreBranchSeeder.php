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
                'name' => 'Head Office',
                'brand_name' => null,
                'brand_code' => null,
                'store_status' => 'Active',
                'tin' => null,
                'complete_address' => null,
                'head_chef' => 'issam.alsuhairy@edi.org.ph',
                'director_operations' => 'kathryn.santiago@edi.org.ph',
                'vp_operations' => 'brendan.stamaria@coffeebean.com.ph'
            ],
            [
                'branch_code' => 'NS',
                'name' => 'NO SO',
                'brand_name' => null,
                'brand_code' => null,
                'store_status' => 'Active',
                'tin' => null,
                'complete_address' => null
            ],
            [
                'branch_code' => 'DS',
                'name' => 'DROPSHIPPING',
                'brand_name' => null,
                'brand_code' => null,
                'store_status' => 'Active',
                'tin' => null,
                'complete_address' => null
            ],
            [
                'branch_code' => 'SMPAS',
                'name' => 'SM PASIG',
                'brand_name' => null,
                'brand_code' => null,
                'store_status' => 'Active',
                'tin' => null,
                'complete_address' => null
            ],
            [
                'branch_code' => 'SMFES',
                'name' => 'SM FESTIVAL',
                'brand_name' => null,
                'brand_code' => null,
                'store_status' => 'Active',
                'tin' => null,
                'complete_address' => null
            ],
            [
                'branch_code' => 'NNVIA',
                'location_code' => 'VIA',
                'name' => 'Arcovia',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => 'Unit A1C, Phase 2, Arcovia Parade, Arcovia City,E. Rodriguez Jr. Avenue, C 5 Road, Pasig City'
            ],
            [
                'branch_code' => 'NNABA',
                'location_code' => 'ABA',
                'name' => 'Ayala Malls Manila Bay',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => 'Space No. 163, 164, 165, G/F, Ayala Malls Bay Area, Diosdado Macapagal Blvd, Brgy. Tambo, Parañaque City'
            ],
            [
                'branch_code' => 'NNFIL',
                'location_code' => 'FIL',
                'name' => 'Filinvest Super Mall',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => 'Unit 2213 Grounf Floor, Festival Supermall Expansion FCC Alabang Zapote Road, Muntinlupa City'
            ],
            [
                'branch_code' => 'NNGWM',
                'location_code' => 'GWM',
                'name' => 'Gateway Mall 2',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => 'Space 15 Block 015R Groundl Floor New Gateway Mall 2, Socorro 1109 Quezon City NCR, Second District Philippines'
            ],
            [
                'branch_code' => 'NNGLO',
                'location_code' => 'GLO1',
                'name' => 'Glorietta 2',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => 'Ground Floor Glorietta 2, Ayala Center, San Lorenzo, Makati City'
            ],
            [
                'branch_code' => 'NNGFD',
                'location_code' => 'GFD',
                'name' => 'Greenfield',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => 'Unit 1, Greenfield Tower, Greenfield District, Barangay Highway Hills, Mandaluyong City'
            ],
            [
                'branch_code' => 'NNHSS',
                'location_code' => 'HSS',
                'name' => 'High Street South',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => 'Unit 5, Ground Floor, Tower 1, Corporate Plaza, HighStreet South, BGC, Fort Bonifacio, Taguig City'
            ],
            [
                'branch_code' => 'NNKLT',
                'location_code' => 'KLT',
                'name' => 'KL Tower Serviced Residences',
                'brand_name' => 'Tabletop Partners, Inc.',
                'brand_code' => 'TTP',
                'store_status' => 'Active',
                'tin' => '010-245-372-000',
                'complete_address' => 'G/F Kalaw Ledesma Tower, 117 Gamboa St., Legaspi Village, San Lorenzo 1223 Makati City'
            ],
            [
                'branch_code' => 'NNLGC',
                'name' => 'Laus Group Complex',
                'brand_name' => 'MTL Premier Food Corporation',
                'brand_code' => 'MTL',
                'store_status' => 'Active',
                'tin' => '651-385-286-00000',
                'complete_address' => 'The Event Center blvd., Laus Group Complex, Jose Abad Santos, San Jose 2000 City of San Fernando Pampanga Philippines'
            ],
            [
                'branch_code' => 'NNOKA',
                'location_code' => 'OKA',
                'name' => 'Nono\'s Okada',
                'brand_name' => 'Passions United, Inc.',
                'brand_code' => 'PSI',
                'store_status' => 'Active',
                'tin' => '615-603-586-00000',
                'complete_address' => 'Unit A5 Upper Ground Floor Crystal Corridor Pearl Wing Okada Manila New Seaside Dr Tambo Parañaque City'
            ],
            [
                'branch_code' => 'NNUTC',
                'location_code' => 'UTC',
                'name' => 'NONO\'S UP TOWN Center',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => 'Units C122 & C151, Phase 2, UP Town Center, Katipunan Avenue, Quezon City'
            ],
            [
                'branch_code' => 'NNNUV',
                'location_code' => 'NUV',
                'name' => 'Nuvali',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => 'GFF 9- Building E Solenad Brgy. Sto. Domingo Sta Rosa City Laguna'
            ],
            [
                'branch_code' => 'NNOPU',
                'name' => 'Opus',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => 'TBD'
            ],
            [
                'branch_code' => 'NNPDM',
                'location_code' => 'PDM',
                'name' => 'Podium Ortigas',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => '5th Level The Podium Ortigas Center Pasig City.'
            ],
            [
                'branch_code' => 'NNRAO',
                'location_code' => 'RAO',
                'name' => 'Robinson\'s Antipolo',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => 'Space #001-002 Lower Ground Floor Robinsons Place Antipolo Expansion, Sumulong Hiway cor. Circumferential Road, Dela Paz (Pob) Antipolo City'
            ],
            [
                'branch_code' => 'NNRWL',
                'location_code' => 'RWL',
                'name' => 'Rockwell',
                'brand_name' => 'Tabletop Partners, Inc.',
                'brand_code' => 'TTP',
                'store_status' => 'Active',
                'tin' => '010-245-372-000',
                'complete_address' => 'Shop 308L R3 - 308, The Power Plant Rockwell, Poblacion, Makati City'
            ],
            [
                'branch_code' => 'NNSMA',
                'location_code' => 'GFD',
                'name' => 'SM Maison',
                'brand_name' => 'Passions United, Inc.',
                'brand_code' => 'PSI',
                'store_status' => 'Active',
                'tin' => '615-603-586-00000',
                'complete_address' => 'S\'Maison 2nd floor Seaside Boulevard, 1300 Coral Way, Pasay, Metro Manila'
            ],
            [
                'branch_code' => 'NNBIC',
                'location_code' => 'SMBIC',
                'name' => 'SM Bicutan',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => 'TBD'
            ],
            [
                'branch_code' => 'NNSFW',
                'location_code' => 'SFW',
                'name' => 'SM Fairview Nono\'s',
                'brand_name' => 'Passions United, Inc.',
                'brand_code' => 'PSI',
                'store_status' => 'Active',
                'tin' => '615-603-586-00000',
                'complete_address' => 'AX3 114-115, Ground Floor SM City Fairview Quirino Highway cor Regalado Avenue Greater Lagro Quezon City'
            ],
            [
                'branch_code' => 'NNSGC',
                'location_code' => 'SGC',
                'name' => 'SM Grand Central',
                'brand_name' => 'Tabletop Partners, Inc.',
                'brand_code' => 'TTP',
                'store_status' => 'Active',
                'tin' => '010-245-372-000',
                'complete_address' => 'Location 138-140 G/F SM City Grand Central Ave Ext  Zone 8 Grace Park East  Barangay 88 District II 1403 Caloocan City'
            ],
            [
                'branch_code' => 'NNSSR',
                'location_code' => 'SSR',
                'name' => 'SM Santa Rosa',
                'brand_name' => 'Tabletop Partners, Inc.',
                'brand_code' => 'TTP',
                'store_status' => 'Active',
                'tin' => '010-245-372-000',
                'complete_address' => 'Exp 1053-1057 Ground Floor Expansion Wing SM Santa Rosa Old National Highway Brgy Tagapo City of Santa Rosa Laguna'
            ],
            [
                'branch_code' => 'TBK-RWL',
                'name' => 'THE BLUE KITCHEN',
                'brand_name' => 'J Beans, Inc.',
                'brand_code' => 'JBI',
                'store_status' => 'Active',
                'tin' => '010-678-947-00000',
                'complete_address' => 'Stall No. 011 P1 Level Powerplant Mall Rockwell Center Brgy Pobalcion Makati City'
            ],
            [
                'branch_code' => 'NNTOL',
                'location_code' => 'TOL',
                'name' => 'The Outlets at Lipa',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => 'Block H, Units F01, R01 - R02 + Alfresco Dining located within The Outlets at Lipa, LIMA Estate, Lipa, Batangas'
            ],
            [
                'branch_code' => 'NN3CN',
                'location_code' => '3CN',
                'name' => 'Three Central',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => 'Unit A5 G/F Two Central Tower 2 Valero St. Salcedo Village, Bel-Air Makati City'
            ],
            [
                'branch_code' => 'NNUPM',
                'location_code' => 'UPM',
                'name' => 'Uptown Mall',
                'brand_name' => 'Tabletop Partners, Inc.',
                'brand_code' => 'TTP',
                'store_status' => 'Active',
                'tin' => '010-245-372-000',
                'complete_address' => 'Unit C4, 3rd Floor Uptown Mall Fort Bonifacio'
            ],
            [
                'branch_code' => 'NNVER',
                'location_code' => 'VER',
                'name' => 'Vermosa',
                'brand_name' => 'Eat, Drink and Innovate Inc.',
                'brand_code' => 'EDI',
                'store_status' => 'Active',
                'tin' => '010-179-134-000',
                'complete_address' => 'Bldg. E GF Unit F5 & F6, Ayala Malls Vermosa, Daang Hari Road,cor Vermosa Blvd.  Pasong Buaya 1, Imus, Cavite City',
            ]
        ];

        foreach ($branches as $branch) {
            $branch['head_chef'] = 'issam.alsuhairy@edi.org.ph';
            $branch['director_operations'] = 'kathryn.santiago@edi.org.ph';
            $branch['vp_operations'] = 'brendan.stamaria@coffeebean.com.ph';
            StoreBranch::create($branch);
        }
    }
}
