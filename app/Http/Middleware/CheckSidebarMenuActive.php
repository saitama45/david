<?php

namespace App\Http\Middleware;

use App\Models\SidebarMenuSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSidebarMenuActive
{
    /**
     * Maps Laravel route names to their sidebar menu_key.
     * Only leaf/page routes need entries here (not groups/subgroups).
     */
    private const ROUTE_MAP = [
        'dashboard'                          => 'dashboard',

        // Ordering > Regular
        'store-orders.index'                 => 'ordering.regular.store-orders',
        'orders-approval.index'              => 'ordering.regular.orders-approval',
        'cs-approvals.index'                 => 'ordering.regular.cs-approvals',

        // Ordering > Regular DTS
        'dts-orders.index'                   => 'ordering.regular-dts.dts-orders',

        // Ordering > Mass
        'mass-orders.index'                  => 'ordering.mass.mass-orders',
        'mass-orders-approval.index'         => 'ordering.mass.mass-orders-approval',
        'cs-mass-commits.index'              => 'ordering.mass.cs-mass-commits',

        // Ordering > DTS Mass
        'dts-mass-orders.index'              => 'ordering.dts-mass.dts-mass-orders',

        // Ordering > Stock Transfer
        'interco.index'                      => 'ordering.stock-transfer.interco',
        'interco-approval.index'             => 'ordering.stock-transfer.interco-approval',
        'store-commits.index'                => 'ordering.stock-transfer.store-commits',

        // Ordering > Tools
        'ordering-calendar.index'            => 'ordering.tools.ordering-calendar',
        'order-calculator.index'             => 'ordering.tools.order-calculator',

        // Ordering > Others
        'emergency-orders.index'             => 'ordering.others.emergency-orders',
        'emergency-orders-approval.index'    => 'ordering.others.emergency-orders-approval',
        'additional-orders.index'            => 'ordering.others.additional-orders',
        'additional-orders-approval.index'   => 'ordering.others.additional-orders-approval',

        // Receiving
        'direct-receiving.index'             => 'receiving.direct-receiving',
        'orders-receiving.index'             => 'receiving.inbound-orders',
        'receiving-approvals.index'          => 'receiving.approvals',
        'approved-orders.index'              => 'receiving.confirmed-approved',
        'interco-receiving.index'            => 'receiving.interco-receiving',

        // Sales
        'store-transactions.index'           => 'sales.store-transactions',
        'store-transactions-approval.index'  => 'sales.store-transactions-approval',
        'sales-budget-uploader.index'        => 'sales.budget-uploader',

        // Inventory
        'stock-management.index'             => 'inventory.stock-management',
        'soh-adjustment.index'               => 'inventory.soh-adjustment',
        'wastage.index'                      => 'inventory.wastage.wastage-record',
        'wastage-approval-level1.index'      => 'inventory.wastage.approval-level1',
        'wastage-approval-level2.index'      => 'inventory.wastage.approval-level2',
        'month-end-count.index'              => 'inventory.mec.month-end-count',
        'month-end-count-approvals.index'    => 'inventory.mec.approval-level1',
        'month-end-count-approvals-level2.index' => 'inventory.mec.approval-level2',
        'low-on-stocks.index'                => 'inventory.low-on-stocks',

        // BOM
        'pos-bom-list.index'                 => 'bom.bom-list',

        // Reports
        'reports.consolidated-so'            => 'reports.consolidated-so',
        'reports.pmix-report'                => 'reports.pmix',
        'reports.wastage-report'             => 'reports.wastage',
        'reports.delivery-report'            => 'reports.delivery',
        'reports.qty-variance-cost-variance' => 'reports.qty-variance',
        'reports.actual-cost-cogs'           => 'reports.actual-cost-cogs',
        'reports.interco-report'             => 'reports.interco',
        'reports.inventory-movement'         => 'reports.inventory-movement',
        'top-10-inventories.index'           => 'reports.top-10-inventories',
        'days-inventory-outstanding.index'   => 'reports.days-inventory-outstanding',
        'days-payable-outstanding.index'     => 'reports.days-payable-outstanding',
        'sales-report.index'                 => 'reports.sales',
        'inventories-report.index'           => 'reports.inventories',
        'upcoming-inventories.index'         => 'reports.upcoming-inventories',
        'account-payable.index'              => 'reports.account-payable',
        'cost-of-goods.index'                => 'reports.cost-of-goods',
        'product-orders-summary.index'       => 'reports.item-orders-summary',
        'ice-cream-orders.index'             => 'reports.ice-cream-orders',
        'salmon-orders.index'                => 'reports.salmon-orders',
        'fruits-and-vegetables.index'        => 'reports.fruits-and-vegetables',

        // References
        'category-list.index'                => 'references.categories',
        'wip-list.index'                     => 'references.wip-list',
        'menu-categories.index'              => 'references.menu-categories',
        'uom-conversions.index'              => 'references.uom-conversions',
        'inventory-categories.index'         => 'references.inventory-categories',
        'unit-of-measurements.index'         => 'references.unit-of-measurements',
        'cost-centers.index'                 => 'references.cost-centers',

        // Administration
        'users.index'                        => 'administration.users',
        'roles.index'                        => 'administration.roles',
        'work-queue.index'                   => 'administration.work-queue',
        'items-list.index'                   => 'administration.masterfile.nn-items',
        'sapitems-list.index'                => 'administration.masterfile.sap',
        'SupplierItems-list.index'           => 'administration.masterfile.supplier-items',
        'POSMasterfile-list.index'           => 'administration.masterfile.pos',
        'branches.index'                     => 'administration.masterfile.branches',
        'suppliers.index'                    => 'administration.masterfile.suppliers',
        'templates.index'                    => 'administration.templates.ordering',
        'ordering-template-approval.index'   => 'administration.templates.ordering-approval',
        'month-end-count-templates.index'    => 'administration.templates.mec',
        'dts-delivery-schedules.index'       => 'administration.schedules.dts-delivery',
        'dsp-delivery-schedules.index'       => 'administration.schedules.dsp-delivery',
        'month-end-schedules.index'          => 'administration.schedules.month-end',
        'orders-cutoff.index'                => 'administration.schedules.orders-cutoff',
        'manage-knowledge-base.index'        => 'administration.knowledge-base',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Admins bypass the check
        if ($request->user()?->hasRole('admin')) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if ($routeName && isset(self::ROUTE_MAP[$routeName])) {
            $menuKey = self::ROUTE_MAP[$routeName];

            $setting = SidebarMenuSetting::where('menu_key', $menuKey)->first();

            if ($setting && !$setting->is_active) {
                abort(403, 'This page has been disabled by your administrator. Please contact your system admin if you believe this is a mistake.');
            }
        }

        return $next($request);
    }
}
