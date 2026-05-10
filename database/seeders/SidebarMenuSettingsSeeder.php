<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SidebarMenuSetting;

class SidebarMenuSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            // Top-level parents (sort_order determines their order in the sidebar)
            ['menu_key' => 'dashboard',        'parent_key' => null, 'sort_order' => 0],
            ['menu_key' => 'ordering',         'parent_key' => null, 'sort_order' => 1],
            ['menu_key' => 'receiving',        'parent_key' => null, 'sort_order' => 2],
            ['menu_key' => 'sales',            'parent_key' => null, 'sort_order' => 3],
            ['menu_key' => 'inventory',        'parent_key' => null, 'sort_order' => 4],
            ['menu_key' => 'bom',              'parent_key' => null, 'sort_order' => 5],
            ['menu_key' => 'reports',          'parent_key' => null, 'sort_order' => 6],
            ['menu_key' => 'references',       'parent_key' => null, 'sort_order' => 7],
            ['menu_key' => 'administration',   'parent_key' => null, 'sort_order' => 8],

            // --- Ordering submenus ---
            ['menu_key' => 'ordering.regular',       'parent_key' => 'ordering', 'sort_order' => 0],
            ['menu_key' => 'ordering.regular-dts',   'parent_key' => 'ordering', 'sort_order' => 1],
            ['menu_key' => 'ordering.mass',          'parent_key' => 'ordering', 'sort_order' => 2],
            ['menu_key' => 'ordering.dts-mass',      'parent_key' => 'ordering', 'sort_order' => 3],
            ['menu_key' => 'ordering.stock-transfer','parent_key' => 'ordering', 'sort_order' => 4],
            ['menu_key' => 'ordering.tools',         'parent_key' => 'ordering', 'sort_order' => 5],
            ['menu_key' => 'ordering.others',        'parent_key' => 'ordering', 'sort_order' => 6],

            // Ordering > Regular children
            ['menu_key' => 'ordering.regular.store-orders',    'parent_key' => 'ordering.regular', 'sort_order' => 0],
            ['menu_key' => 'ordering.regular.orders-approval', 'parent_key' => 'ordering.regular', 'sort_order' => 1],
            ['menu_key' => 'ordering.regular.cs-approvals',    'parent_key' => 'ordering.regular', 'sort_order' => 2],

            // Ordering > Regular DTS children
            ['menu_key' => 'ordering.regular-dts.dts-orders', 'parent_key' => 'ordering.regular-dts', 'sort_order' => 0],

            // Ordering > Mass children
            ['menu_key' => 'ordering.mass.mass-orders',         'parent_key' => 'ordering.mass', 'sort_order' => 0],
            ['menu_key' => 'ordering.mass.mass-orders-approval','parent_key' => 'ordering.mass', 'sort_order' => 1],
            ['menu_key' => 'ordering.mass.cs-mass-commits',     'parent_key' => 'ordering.mass', 'sort_order' => 2],

            // Ordering > DTS Mass (direct link, treated as child of ordering)
            ['menu_key' => 'ordering.dts-mass.dts-mass-orders', 'parent_key' => 'ordering.dts-mass', 'sort_order' => 0],

            // Ordering > Stock Transfer children
            ['menu_key' => 'ordering.stock-transfer.interco',          'parent_key' => 'ordering.stock-transfer', 'sort_order' => 0],
            ['menu_key' => 'ordering.stock-transfer.interco-approval', 'parent_key' => 'ordering.stock-transfer', 'sort_order' => 1],
            ['menu_key' => 'ordering.stock-transfer.store-commits',    'parent_key' => 'ordering.stock-transfer', 'sort_order' => 2],

            // Ordering > Tools children
            ['menu_key' => 'ordering.tools.ordering-calendar',   'parent_key' => 'ordering.tools', 'sort_order' => 0],
            ['menu_key' => 'ordering.tools.order-calculator',    'parent_key' => 'ordering.tools', 'sort_order' => 1],

            // Ordering > Others children
            ['menu_key' => 'ordering.others.emergency-orders',          'parent_key' => 'ordering.others', 'sort_order' => 0],
            ['menu_key' => 'ordering.others.emergency-orders-approval', 'parent_key' => 'ordering.others', 'sort_order' => 1],
            ['menu_key' => 'ordering.others.additional-orders',         'parent_key' => 'ordering.others', 'sort_order' => 2],
            ['menu_key' => 'ordering.others.additional-orders-approval','parent_key' => 'ordering.others', 'sort_order' => 3],

            // --- Receiving children ---
            ['menu_key' => 'receiving.direct-receiving',    'parent_key' => 'receiving', 'sort_order' => 0],
            ['menu_key' => 'receiving.inbound-orders',      'parent_key' => 'receiving', 'sort_order' => 1],
            ['menu_key' => 'receiving.approvals',           'parent_key' => 'receiving', 'sort_order' => 2],
            ['menu_key' => 'receiving.confirmed-approved',  'parent_key' => 'receiving', 'sort_order' => 3],
            ['menu_key' => 'receiving.interco-receiving',   'parent_key' => 'receiving', 'sort_order' => 4],

            // --- Sales children ---
            ['menu_key' => 'sales.store-transactions',         'parent_key' => 'sales', 'sort_order' => 0],
            ['menu_key' => 'sales.store-transactions-approval','parent_key' => 'sales', 'sort_order' => 1],
            ['menu_key' => 'sales.budget-uploader',            'parent_key' => 'sales', 'sort_order' => 2],

            // --- Inventory children ---
            ['menu_key' => 'inventory.stock-management', 'parent_key' => 'inventory', 'sort_order' => 0],
            ['menu_key' => 'inventory.soh-adjustment',   'parent_key' => 'inventory', 'sort_order' => 1],
            ['menu_key' => 'inventory.wastage',          'parent_key' => 'inventory', 'sort_order' => 2],
            ['menu_key' => 'inventory.mec',              'parent_key' => 'inventory', 'sort_order' => 3],
            ['menu_key' => 'inventory.low-on-stocks',    'parent_key' => 'inventory', 'sort_order' => 4],

            // Inventory > Wastage children
            ['menu_key' => 'inventory.wastage.wastage-record',     'parent_key' => 'inventory.wastage', 'sort_order' => 0],
            ['menu_key' => 'inventory.wastage.approval-level1',    'parent_key' => 'inventory.wastage', 'sort_order' => 1],
            ['menu_key' => 'inventory.wastage.approval-level2',    'parent_key' => 'inventory.wastage', 'sort_order' => 2],

            // Inventory > MEC children
            ['menu_key' => 'inventory.mec.month-end-count',          'parent_key' => 'inventory.mec', 'sort_order' => 0],
            ['menu_key' => 'inventory.mec.approval-level1',          'parent_key' => 'inventory.mec', 'sort_order' => 1],
            ['menu_key' => 'inventory.mec.approval-level2',          'parent_key' => 'inventory.mec', 'sort_order' => 2],

            // --- BOM children ---
            ['menu_key' => 'bom.bom-list', 'parent_key' => 'bom', 'sort_order' => 0],

            // --- Reports children ---
            ['menu_key' => 'reports.consolidated-so',              'parent_key' => 'reports', 'sort_order' => 0],
            ['menu_key' => 'reports.pmix',                         'parent_key' => 'reports', 'sort_order' => 1],
            ['menu_key' => 'reports.wastage',                      'parent_key' => 'reports', 'sort_order' => 2],
            ['menu_key' => 'reports.delivery',                     'parent_key' => 'reports', 'sort_order' => 3],
            ['menu_key' => 'reports.qty-variance',                 'parent_key' => 'reports', 'sort_order' => 4],
            ['menu_key' => 'reports.actual-cost-cogs',             'parent_key' => 'reports', 'sort_order' => 5],
            ['menu_key' => 'reports.interco',                      'parent_key' => 'reports', 'sort_order' => 6],
            ['menu_key' => 'reports.inventory-movement',           'parent_key' => 'reports', 'sort_order' => 7],
            ['menu_key' => 'reports.top-10-inventories',           'parent_key' => 'reports', 'sort_order' => 8],
            ['menu_key' => 'reports.days-inventory-outstanding',   'parent_key' => 'reports', 'sort_order' => 9],
            ['menu_key' => 'reports.days-payable-outstanding',     'parent_key' => 'reports', 'sort_order' => 10],
            ['menu_key' => 'reports.sales',                        'parent_key' => 'reports', 'sort_order' => 11],
            ['menu_key' => 'reports.inventories',                  'parent_key' => 'reports', 'sort_order' => 12],
            ['menu_key' => 'reports.upcoming-inventories',         'parent_key' => 'reports', 'sort_order' => 13],
            ['menu_key' => 'reports.account-payable',              'parent_key' => 'reports', 'sort_order' => 14],
            ['menu_key' => 'reports.cost-of-goods',                'parent_key' => 'reports', 'sort_order' => 15],
            ['menu_key' => 'reports.item-orders-summary',          'parent_key' => 'reports', 'sort_order' => 16],
            ['menu_key' => 'reports.ice-cream-orders',             'parent_key' => 'reports', 'sort_order' => 17],
            ['menu_key' => 'reports.salmon-orders',                'parent_key' => 'reports', 'sort_order' => 18],
            ['menu_key' => 'reports.fruits-and-vegetables',        'parent_key' => 'reports', 'sort_order' => 19],

            // --- References children ---
            ['menu_key' => 'references.categories',          'parent_key' => 'references', 'sort_order' => 0],
            ['menu_key' => 'references.wip-list',            'parent_key' => 'references', 'sort_order' => 1],
            ['menu_key' => 'references.menu-categories',     'parent_key' => 'references', 'sort_order' => 2],
            ['menu_key' => 'references.uom-conversions',     'parent_key' => 'references', 'sort_order' => 3],
            ['menu_key' => 'references.inventory-categories','parent_key' => 'references', 'sort_order' => 4],
            ['menu_key' => 'references.unit-of-measurements','parent_key' => 'references', 'sort_order' => 5],
            ['menu_key' => 'references.cost-centers',        'parent_key' => 'references', 'sort_order' => 6],

            // --- Administration children ---
            ['menu_key' => 'administration.users',          'parent_key' => 'administration', 'sort_order' => 0],
            ['menu_key' => 'administration.roles',          'parent_key' => 'administration', 'sort_order' => 1],
            ['menu_key' => 'administration.work-queue',     'parent_key' => 'administration', 'sort_order' => 2],
            ['menu_key' => 'administration.masterfile',     'parent_key' => 'administration', 'sort_order' => 3],
            ['menu_key' => 'administration.templates',      'parent_key' => 'administration', 'sort_order' => 4],
            ['menu_key' => 'administration.schedules',      'parent_key' => 'administration', 'sort_order' => 5],
            ['menu_key' => 'administration.knowledge-base', 'parent_key' => 'administration', 'sort_order' => 6],
            ['menu_key' => 'administration.sidebar-management', 'parent_key' => 'administration', 'sort_order' => 7],

            // Administration > Masterfile children
            ['menu_key' => 'administration.masterfile.nn-items',  'parent_key' => 'administration.masterfile', 'sort_order' => 0],
            ['menu_key' => 'administration.masterfile.sap',       'parent_key' => 'administration.masterfile', 'sort_order' => 1],
            ['menu_key' => 'administration.masterfile.supplier-items','parent_key' => 'administration.masterfile', 'sort_order' => 2],
            ['menu_key' => 'administration.masterfile.pos',       'parent_key' => 'administration.masterfile', 'sort_order' => 3],
            ['menu_key' => 'administration.masterfile.branches',  'parent_key' => 'administration.masterfile', 'sort_order' => 4],
            ['menu_key' => 'administration.masterfile.suppliers', 'parent_key' => 'administration.masterfile', 'sort_order' => 5],

            // Administration > Templates children
            ['menu_key' => 'administration.templates.ordering',          'parent_key' => 'administration.templates', 'sort_order' => 0],
            ['menu_key' => 'administration.templates.ordering-approval', 'parent_key' => 'administration.templates', 'sort_order' => 1],
            ['menu_key' => 'administration.templates.mec',               'parent_key' => 'administration.templates', 'sort_order' => 2],

            // Administration > Schedules children
            ['menu_key' => 'administration.schedules.dts-delivery',  'parent_key' => 'administration.schedules', 'sort_order' => 0],
            ['menu_key' => 'administration.schedules.dsp-delivery',  'parent_key' => 'administration.schedules', 'sort_order' => 1],
            ['menu_key' => 'administration.schedules.month-end',     'parent_key' => 'administration.schedules', 'sort_order' => 2],
            ['menu_key' => 'administration.schedules.orders-cutoff', 'parent_key' => 'administration.schedules', 'sort_order' => 3],
        ];

        foreach ($items as $item) {
            SidebarMenuSetting::updateOrCreate(
                ['menu_key' => $item['menu_key']],
                array_merge($item, ['custom_label' => null, 'is_active' => true])
            );
        }
    }
}
