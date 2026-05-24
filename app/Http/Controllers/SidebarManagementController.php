<?php

namespace App\Http\Controllers;

use App\Models\SidebarMenuSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SidebarManagementController extends Controller
{
    /**
     * Default menu structure — single source of truth for labels, hierarchy, and display metadata.
     * The Vue sidebar still controls icons and permission gates.
     */
    private const DEFAULT_STRUCTURE = [
        ['menu_key' => 'dashboard',        'parent_key' => null,                        'default_label' => 'Dashboard',                        'level' => 0],
        ['menu_key' => 'ordering',         'parent_key' => null,                        'default_label' => 'Ordering',                         'level' => 0],
        ['menu_key' => 'ordering.regular', 'parent_key' => 'ordering',                  'default_label' => 'Regular',                          'level' => 1],
        ['menu_key' => 'ordering.regular.store-orders',    'parent_key' => 'ordering.regular',  'default_label' => 'Store Orders',             'level' => 2],
        ['menu_key' => 'ordering.regular.orders-approval', 'parent_key' => 'ordering.regular',  'default_label' => 'Orders Approval',          'level' => 2],
        ['menu_key' => 'ordering.regular.cs-approvals',    'parent_key' => 'ordering.regular',  'default_label' => 'CS Review List',           'level' => 2],
        ['menu_key' => 'ordering.regular-dts',             'parent_key' => 'ordering',           'default_label' => 'Regular DTS',              'level' => 1],
        ['menu_key' => 'ordering.regular-dts.dts-orders',  'parent_key' => 'ordering.regular-dts','default_label' => 'DTS Orders',             'level' => 2],
        ['menu_key' => 'ordering.mass',                    'parent_key' => 'ordering',           'default_label' => 'Regular Mass Orders',      'level' => 1],
        ['menu_key' => 'ordering.mass.mass-orders',         'parent_key' => 'ordering.mass',     'default_label' => 'Mass Orders',             'level' => 2],
        ['menu_key' => 'ordering.mass.mass-orders-approval','parent_key' => 'ordering.mass',     'default_label' => 'Mass Orders Approval',    'level' => 2],
        ['menu_key' => 'ordering.mass.cs-mass-commits',     'parent_key' => 'ordering.mass',     'default_label' => 'CS Mass Commits',         'level' => 2],
        ['menu_key' => 'ordering.dts-mass',                 'parent_key' => 'ordering',          'default_label' => 'DTS Mass Orders',          'level' => 1],
        ['menu_key' => 'ordering.dts-mass.dts-mass-orders', 'parent_key' => 'ordering.dts-mass', 'default_label' => 'DTS Mass Orders',         'level' => 2],
        ['menu_key' => 'ordering.stock-transfer',           'parent_key' => 'ordering',          'default_label' => 'Stock Transfer',           'level' => 1],
        ['menu_key' => 'ordering.stock-transfer.interco',          'parent_key' => 'ordering.stock-transfer', 'default_label' => 'Interco Transfer',   'level' => 2],
        ['menu_key' => 'ordering.stock-transfer.interco-approval', 'parent_key' => 'ordering.stock-transfer', 'default_label' => 'Interco Approval',   'level' => 2],
        ['menu_key' => 'ordering.stock-transfer.store-commits',    'parent_key' => 'ordering.stock-transfer', 'default_label' => 'Store Commits',      'level' => 2],
        ['menu_key' => 'ordering.tools',                    'parent_key' => 'ordering',          'default_label' => 'Ordering Tools',           'level' => 1],
        ['menu_key' => 'ordering.tools.ordering-calendar',  'parent_key' => 'ordering.tools',    'default_label' => 'Ordering Calendar',       'level' => 2],
        ['menu_key' => 'ordering.tools.order-calculator',   'parent_key' => 'ordering.tools',    'default_label' => 'Order Calculator',        'level' => 2],
        ['menu_key' => 'ordering.others',                   'parent_key' => 'ordering',          'default_label' => 'Others',                   'level' => 1],
        ['menu_key' => 'ordering.others.emergency-orders',           'parent_key' => 'ordering.others', 'default_label' => 'Emergency Orders',          'level' => 2],
        ['menu_key' => 'ordering.others.emergency-orders-approval',  'parent_key' => 'ordering.others', 'default_label' => 'Emergency Order Approval',  'level' => 2],
        ['menu_key' => 'ordering.others.additional-orders',          'parent_key' => 'ordering.others', 'default_label' => 'Additional Orders',         'level' => 2],
        ['menu_key' => 'ordering.others.additional-orders-approval', 'parent_key' => 'ordering.others', 'default_label' => 'Additional Order Approval', 'level' => 2],

        ['menu_key' => 'receiving',                   'parent_key' => null,         'default_label' => 'Receiving',                       'level' => 0],
        ['menu_key' => 'receiving.direct-receiving',  'parent_key' => 'receiving',  'default_label' => 'Direct Receiving',                'level' => 1],
        ['menu_key' => 'receiving.inbound-orders',    'parent_key' => 'receiving',  'default_label' => 'Inbound Orders',                  'level' => 1],
        ['menu_key' => 'receiving.approvals',         'parent_key' => 'receiving',  'default_label' => 'Receiving Approvals',             'level' => 1],
        ['menu_key' => 'receiving.confirmed-approved','parent_key' => 'receiving',  'default_label' => 'Confirmed/Approved Received SO',  'level' => 1],
        ['menu_key' => 'receiving.interco-receiving', 'parent_key' => 'receiving',  'default_label' => 'Interco Receiving',               'level' => 1],

        ['menu_key' => 'sales',                            'parent_key' => null,    'default_label' => 'Sales',                           'level' => 0],
        ['menu_key' => 'sales.store-transactions',         'parent_key' => 'sales', 'default_label' => 'Store Transactions',              'level' => 1],
        ['menu_key' => 'sales.store-transactions-approval','parent_key' => 'sales', 'default_label' => 'Store Transactions Approval',     'level' => 1],
        ['menu_key' => 'sales.budget-uploader',            'parent_key' => 'sales', 'default_label' => 'Sales/Budget Uploader',           'level' => 1],

        ['menu_key' => 'inventory',              'parent_key' => null,          'default_label' => 'Inventory',                'level' => 0],
        ['menu_key' => 'inventory.stock-management','parent_key' => 'inventory', 'default_label' => 'Stock Management',        'level' => 1],
        ['menu_key' => 'inventory.soh-adjustment',  'parent_key' => 'inventory', 'default_label' => 'SOH Adjustment',         'level' => 1],
        ['menu_key' => 'inventory.wastage',          'parent_key' => 'inventory', 'default_label' => 'Wastage',               'level' => 1],
        ['menu_key' => 'inventory.wastage.wastage-record',  'parent_key' => 'inventory.wastage', 'default_label' => 'Wastage Record',           'level' => 2],
        ['menu_key' => 'inventory.wastage.approval-level1', 'parent_key' => 'inventory.wastage', 'default_label' => 'Wastage Approval 1st Level','level' => 2],
        ['menu_key' => 'inventory.wastage.approval-level2', 'parent_key' => 'inventory.wastage', 'default_label' => 'Wastage Approval 2nd Level','level' => 2],
        ['menu_key' => 'inventory.mec',              'parent_key' => 'inventory', 'default_label' => 'MEC',                   'level' => 1],
        ['menu_key' => 'inventory.mec.month-end-count', 'parent_key' => 'inventory.mec', 'default_label' => 'Month End Count',         'level' => 2],
        ['menu_key' => 'inventory.mec.approval-level1', 'parent_key' => 'inventory.mec', 'default_label' => 'MEC Approval 1st Level',  'level' => 2],
        ['menu_key' => 'inventory.mec.approval-level2', 'parent_key' => 'inventory.mec', 'default_label' => 'MEC Approval 2nd Level',  'level' => 2],
        ['menu_key' => 'inventory.low-on-stocks',    'parent_key' => 'inventory', 'default_label' => 'Low on Stocks',         'level' => 1],

        ['menu_key' => 'bom',          'parent_key' => null,  'default_label' => 'Bill of Materials', 'level' => 0],
        ['menu_key' => 'bom.bom-list', 'parent_key' => 'bom', 'default_label' => 'BOM List',          'level' => 1],

        ['menu_key' => 'reports',                             'parent_key' => null,      'default_label' => 'Reports',                             'level' => 0],
        ['menu_key' => 'reports.consolidated-so',             'parent_key' => 'reports', 'default_label' => 'Consolidated SO Report',              'level' => 1],
        ['menu_key' => 'reports.pmix',                        'parent_key' => 'reports', 'default_label' => 'PMIX Report',                         'level' => 1],
        ['menu_key' => 'reports.wastage',                     'parent_key' => 'reports', 'default_label' => 'Wastage Report',                      'level' => 1],
        ['menu_key' => 'reports.delivery',                    'parent_key' => 'reports', 'default_label' => 'Delivery Report',                     'level' => 1],
        ['menu_key' => 'reports.qty-variance',                'parent_key' => 'reports', 'default_label' => 'Qty Variance / Cost Variance Report', 'level' => 1],
        ['menu_key' => 'reports.actual-cost-cogs',            'parent_key' => 'reports', 'default_label' => 'Actual Cost / COGS Report',           'level' => 1],
        ['menu_key' => 'reports.interco',                     'parent_key' => 'reports', 'default_label' => 'Interco Report',                      'level' => 1],
        ['menu_key' => 'reports.inventory-movement',          'parent_key' => 'reports', 'default_label' => 'Inventory Movement Report',           'level' => 1],
        ['menu_key' => 'reports.top-10-inventories',          'parent_key' => 'reports', 'default_label' => 'Top 10 Inventories',                  'level' => 1],
        ['menu_key' => 'reports.days-inventory-outstanding',  'parent_key' => 'reports', 'default_label' => 'Days Inventory Outstanding',          'level' => 1],
        ['menu_key' => 'reports.days-payable-outstanding',    'parent_key' => 'reports', 'default_label' => 'Days Payable Outstanding',            'level' => 1],
        ['menu_key' => 'reports.sales',                       'parent_key' => 'reports', 'default_label' => 'Sales Report',                        'level' => 1],
        ['menu_key' => 'reports.inventories',                 'parent_key' => 'reports', 'default_label' => 'Inventories Report',                  'level' => 1],
        ['menu_key' => 'reports.upcoming-inventories',        'parent_key' => 'reports', 'default_label' => 'Upcoming Inventories',                'level' => 1],
        ['menu_key' => 'reports.account-payable',             'parent_key' => 'reports', 'default_label' => 'Account Payable',                     'level' => 1],
        ['menu_key' => 'reports.cost-of-goods',               'parent_key' => 'reports', 'default_label' => 'Cost Of Goods',                       'level' => 1],
        ['menu_key' => 'reports.item-orders-summary',         'parent_key' => 'reports', 'default_label' => 'Item Orders Summary',                 'level' => 1],
        ['menu_key' => 'reports.ice-cream-orders',            'parent_key' => 'reports', 'default_label' => 'Ice Cream Orders',                    'level' => 1],
        ['menu_key' => 'reports.salmon-orders',               'parent_key' => 'reports', 'default_label' => 'Salmon Orders',                       'level' => 1],
        ['menu_key' => 'reports.fruits-and-vegetables',       'parent_key' => 'reports', 'default_label' => 'Fruits And Vegetables Orders',        'level' => 1],

        ['menu_key' => 'references',                     'parent_key' => null,          'default_label' => 'References',          'level' => 0],
        ['menu_key' => 'references.categories',          'parent_key' => 'references',  'default_label' => 'Categories',          'level' => 1],
        ['menu_key' => 'references.wip-list',            'parent_key' => 'references',  'default_label' => 'WIP List',            'level' => 1],
        ['menu_key' => 'references.menu-categories',     'parent_key' => 'references',  'default_label' => 'Menu Categories',     'level' => 1],
        ['menu_key' => 'references.uom-conversions',     'parent_key' => 'references',  'default_label' => 'UOM Conversions',     'level' => 1],
        ['menu_key' => 'references.inventory-categories','parent_key' => 'references',  'default_label' => 'Inventory Categories','level' => 1],
        ['menu_key' => 'references.unit-of-measurements','parent_key' => 'references',  'default_label' => 'Unit of Measurements','level' => 1],
        ['menu_key' => 'references.cost-centers',        'parent_key' => 'references',  'default_label' => 'Cost Centers',        'level' => 1],

        ['menu_key' => 'administration',                          'parent_key' => null,               'default_label' => 'Administration',              'level' => 0],
        ['menu_key' => 'administration.users',                    'parent_key' => 'administration',   'default_label' => 'Users',                       'level' => 1],
        ['menu_key' => 'administration.roles',                    'parent_key' => 'administration',   'default_label' => 'Roles',                       'level' => 1],
        ['menu_key' => 'administration.work-queue',               'parent_key' => 'administration',   'default_label' => 'Work Queue',                  'level' => 1],
        ['menu_key' => 'administration.masterfile',               'parent_key' => 'administration',   'default_label' => 'Masterfile',                  'level' => 1],
        ['menu_key' => 'administration.masterfile.nn-items',      'parent_key' => 'administration.masterfile', 'default_label' => 'NN Inventory Items', 'level' => 2],
        ['menu_key' => 'administration.masterfile.sap',           'parent_key' => 'administration.masterfile', 'default_label' => 'SAP Masterlist',     'level' => 2],
        ['menu_key' => 'administration.masterfile.supplier-items','parent_key' => 'administration.masterfile', 'default_label' => 'Supplier Items',     'level' => 2],
        ['menu_key' => 'administration.masterfile.pos',           'parent_key' => 'administration.masterfile', 'default_label' => 'POS Masterlist',     'level' => 2],
        ['menu_key' => 'administration.masterfile.branches',      'parent_key' => 'administration.masterfile', 'default_label' => 'Store Branches',     'level' => 2],
        ['menu_key' => 'administration.masterfile.suppliers',     'parent_key' => 'administration.masterfile', 'default_label' => 'Suppliers',          'level' => 2],
        ['menu_key' => 'administration.templates',                'parent_key' => 'administration',   'default_label' => 'Templates',                   'level' => 1],
        ['menu_key' => 'administration.templates.ordering',          'parent_key' => 'administration.templates', 'default_label' => 'Ordering Templates',         'level' => 2],
        ['menu_key' => 'administration.templates.ordering-approval', 'parent_key' => 'administration.templates', 'default_label' => 'Ordering Template Approval', 'level' => 2],
        ['menu_key' => 'administration.templates.mec',               'parent_key' => 'administration.templates', 'default_label' => 'Month End Count Templates',  'level' => 2],
        ['menu_key' => 'administration.schedules',                'parent_key' => 'administration',   'default_label' => 'Schedules',                   'level' => 1],
        ['menu_key' => 'administration.schedules.dts-delivery',   'parent_key' => 'administration.schedules', 'default_label' => 'DTS Delivery Schedules',      'level' => 2],
        ['menu_key' => 'administration.schedules.dsp-delivery',   'parent_key' => 'administration.schedules', 'default_label' => 'Delivery Schedules',          'level' => 2],
        ['menu_key' => 'administration.schedules.month-end',      'parent_key' => 'administration.schedules', 'default_label' => 'Month End Count Schedules',   'level' => 2],
        ['menu_key' => 'administration.schedules.orders-cutoff',  'parent_key' => 'administration.schedules', 'default_label' => 'Ordering Cut off',            'level' => 2],
        ['menu_key' => 'administration.knowledge-base',           'parent_key' => 'administration',   'default_label' => 'Knowledge Base Articles',      'level' => 1],
        ['menu_key' => 'administration.wastage-settings',         'parent_key' => 'administration',   'default_label' => 'Wastage Settings',             'level' => 1],
        ['menu_key' => 'administration.sidebar-management',       'parent_key' => 'administration',   'default_label' => 'Sidebar Management',           'level' => 1],
    ];

    public function index()
    {
        $settings = SidebarMenuSetting::all()->keyBy('menu_key');

        $menuItems = collect(self::DEFAULT_STRUCTURE)->map(function ($item) use ($settings) {
            $setting = $settings->get($item['menu_key']);
            return array_merge($item, [
                'custom_label' => $setting?->custom_label,
                'sort_order'   => $setting?->sort_order ?? 999,
                'is_active'    => $setting ? $setting->is_active : true,
            ]);
        })->sortBy('sort_order')->values();

        return \Inertia\Inertia::render('SidebarManagement/Index', [
            'menuItems' => $menuItems,
        ]);
    }

    public function bulkUpdate(Request $request)
    {
        $request->validate([
            'items'                => 'required|array',
            'items.*.menu_key'     => 'required|string',
            'items.*.parent_key'   => 'nullable|string',
            'items.*.custom_label' => 'nullable|string|max:100',
            'items.*.sort_order'   => 'required|integer',
            'items.*.is_active'    => 'required|boolean',
        ]);

        foreach ($request->items as $item) {
            SidebarMenuSetting::updateOrCreate(
                ['menu_key' => $item['menu_key']],
                [
                    'parent_key'   => $item['parent_key'],
                    'custom_label' => $item['custom_label'] ?: null,
                    'sort_order'   => $item['sort_order'],
                    'is_active'    => $item['is_active'],
                ]
            );
        }

        return back()->with('success', 'Sidebar settings saved successfully.');
    }
}
