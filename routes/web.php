<?php

use App\Http\Controllers\AccountPayableController;
use App\Http\Controllers\AdditionalOrderApprovalController;
use App\Http\Controllers\AdditionalOrderController;
use App\Http\Controllers\ApprovedReceivedItem;
use App\Http\Controllers\ApprovedOrderController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CashPullOutApproval;
use App\Http\Controllers\CashPullOutApprovalController;
use App\Http\Controllers\CSMassCommitsController;
use App\Http\Controllers\CSDTSMassCommitController;
use App\Http\Controllers\DTSMassOrdersController;
use App\Http\Controllers\CashPullOutController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ConsolidatedSOReportController;
use App\Http\Controllers\CostCenterController;
use App\Http\Controllers\CostOfGoodController;
use App\Http\Controllers\CSApprovalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MonthEndCountApprovalController;
use App\Http\Controllers\MECApproval2Controller;
use App\Http\Controllers\MonthEndCountController;
use App\Http\Controllers\MonthEndScheduleController;
use App\Http\Controllers\DaysInventoryOutstanding;
use App\Http\Controllers\DaysPayableOutStanding;
use App\Http\Controllers\DeliveryScheduleController;
use App\Http\Controllers\DirectReceivingController;
use App\Http\Controllers\DSPDeliveryScheduleController;
use App\Http\Controllers\DTSController;
use App\Http\Controllers\EmergencyOrderApprovalController;
use App\Http\Controllers\EmergencyOrderController;
use App\Http\Controllers\ExcelTemplateController;
use App\Http\Controllers\FruitAndVegetableController;
use App\Http\Controllers\IceCreamOrderController;
use App\Http\Controllers\InventoryReportController;
use App\Http\Controllers\InvetoryCategoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\LowOnStockController;
use App\Http\Controllers\MassOrdersController;
use App\Http\Controllers\MenuCategoryController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderApprovalController;
use App\Http\Controllers\OrderReceivingController;
use App\Http\Controllers\PDFReportController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\POSMasterfileBOMController;
use App\Http\Controllers\POSMasterfileController;
use App\Http\Controllers\ProductOrderSummaryController;
use App\Http\Controllers\ProductSalesController;
use App\Http\Controllers\ReceivingApprovalController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SalmonOrderController;
use App\Http\Controllers\SAPItemController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\SOHAdjustmentController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StoreCommitsController;
use App\Http\Controllers\StockManagementController;
use App\Http\Controllers\StoreBranchController;
use App\Http\Controllers\StoreOrderController;
use App\Http\Controllers\IntercoController;
use App\Http\Controllers\IntercoApprovalController;
use App\Http\Controllers\IntercoReceivingController;
use App\Http\Controllers\IntercoReportController;
use App\Http\Controllers\PMIXReportController;
use App\Http\Controllers\StoreTransactionApprovalController;
use App\Http\Controllers\StoreTransactionController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierItemController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\Top10InventoriesController;
use App\Http\Controllers\UnitOfMeasurementController;
use App\Http\Controllers\UOMConversionController;
use App\Http\Controllers\UpcomingInventoryController;
use App\Http\Controllers\UsageRecordController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WIPController;
use App\Http\Controllers\WastageController;
use App\Http\Controllers\WastageApprovalLevel1Controller;
use App\Http\Controllers\WastageApprovalLevel2Controller;
use App\Http\Controllers\WastageReportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\SAPMasterfileController;
use App\Http\Controllers\SupplierItemsController;
use App\Http\Controllers\WIPListController;
use App\Http\Controllers\OrdersCutoffController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Illuminate\Foundation\Application;


Route::redirect('/', '/dashboard');

Route::get('jobs', function () {
    dispatch(function () {
        logger('test');
    });
});


Route::middleware('auth')
    ->group(function () {

        Route::resource('additional-orders-approval', AdditionalOrderApprovalController::class)->middleware('permission:view additional order approval'); // Added middleware

        Route::resource('emergency-orders-approval', EmergencyOrderApprovalController::class)->middleware('permission:view emergency order approval'); // Added middleware

        Route::controller(WIPListController::class)->name('wip-list.')->prefix('wip-list')->group(function () { // Corrected controller name
            Route::middleware('permission:view wip list')->get('/', 'index')->name('index');
            Route::middleware('permission:create wip')->post('/import-wip-list', 'importWipList')->name('import-wip-list');
            Route::middleware('permission:create wip')->post('/import-wip-ingredients', 'importWipIngredients')->name('import-wip-ingredients');
            Route::middleware('permission:view wip list')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:edit wip')->post('/update/{id}', 'update')->name('update'); // Added update route
            Route::middleware('permission:delete wip')->delete('/destroy/{id}', 'destroy')->name('destroy'); // Added destroy route
            Route::middleware('permission:export wip list')->get('/export', 'export')->name('export'); // Added export route
        });

        Route::resource('emergency-orders', EmergencyOrderController::class)->middleware('permission:view emergency orders'); // Added middleware
        Route::resource('additional-orders', AdditionalOrderController::class)->middleware('permission:view additional orders'); // Added middleware

        // MODIFIED: Template Management Routes - Grouped and named
        Route::controller(TemplateController::class)->prefix('templates')->name('templates.')->group(function () {
            Route::middleware('permission:view templates')->get('/', 'index')->name('index');
            Route::middleware('permission:view template')->get('/show/{template}', 'show')->name('show');
            Route::middleware('permission:create templates')->group(function () {
                Route::get('/create', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
            });
            Route::middleware('permission:edit templates')->group(function () {
                Route::get('/edit/{template}', 'edit')->name('edit');
                Route::put('/update/{template}', 'update')->name('update');
            });
            Route::middleware('permission:delete templates')->delete('/destroy/{template}', 'destroy')->name('destroy');
            Route::middleware('permission:export templates')->get('/export', 'export')->name('export'); // Assuming an export route
        });


        Route::get('/test-report', [PDFReportController::class, 'index']);

        Route::controller(PDFReportController::class)->name('pdf-export.')->prefix('pdf-export')->group(function () {
            Route::get('/store-orders', 'storeOrders')->name('store-orders');
        });

        Route::resource('low-on-stocks', LowOnStockController::class)->middleware('permission:view low on stocks'); // Added middleware

        Route::controller(Top10InventoriesController::class)
            ->name('top-10-inventories.')
            ->prefix('top-10-inventories')
            ->group(function () {
                Route::middleware('permission:view top 10 inventories')->get('/', 'index')->name('index');
                Route::middleware('permission:export top 10 inventories')->get('/export', 'export')->name('export'); // Added export
            });

        Route::prefix('reports')->group(function () {
            Route::get('/consolidated-so', [ConsolidatedSOReportController::class, 'index'])->name('reports.consolidated-so.index');
            Route::get('/consolidated-so/export', [ConsolidatedSOReportController::class, 'export'])->name('reports.consolidated-so.export');
            Route::get('/interco-report', [IntercoReportController::class, 'index'])->name('reports.interco-report.index');
            Route::get('/interco-report/export', [IntercoReportController::class, 'export'])->name('reports.interco-report.export');
            Route::middleware('permission:view pmix report')->get('/pmix-report', [PMIXReportController::class, 'index'])->name('reports.pmix-report.index');
            Route::middleware('permission:export pmix report')->get('/pmix-report/export', [PMIXReportController::class, 'export'])->name('reports.pmix-report.export');
            Route::middleware('permission:view wastage report')->get('/wastage-report', [WastageReportController::class, 'index'])->name('reports.wastage-report.index');
            Route::middleware('permission:export wastage report')->get('/wastage-report/export', [WastageReportController::class, 'export'])->name('reports.wastage-report.export');
        });

        Route::controller(DaysPayableOutStanding::class)
            ->name('days-payable-outstanding.')
            ->prefix('days-payable-outstanding')
            ->group(function () {
                Route::middleware('permission:view days payable outstanding')->get('/', 'index')->name('index');
                Route::middleware('permission:export days payable outstanding')->get('/export', 'export')->name('export'); // Added export
            });

        Route::controller(DaysInventoryOutstanding::class)
            ->name('days-inventory-outstanding.')
            ->prefix('days-inventory-outstanding')
            ->group(function () {
                Route::middleware('permission:view days inventory outstanding')->get('/', 'index')->name('index');
                Route::middleware('permission:export days inventory outstanding')->get('/export', 'export')->name('export'); // Added export
            });

        Route::controller(InventoryReportController::class)
            ->name('inventories-report.')
            ->prefix('inventories-report')
            ->group(function () {
                Route::middleware('permission:view inventories report')->get('/', 'index')->name('index');
                Route::middleware('permission:export inventories report')->get('/export', 'export')->name('export'); // Added export
            });


        Route::controller(CostOfGoodController::class)
            ->name('cost-of-goods.')
            ->prefix('cost-of-goods')
            ->group(function () {
                Route::middleware('permission:view cost of goods')->get('/', 'index')->name('index');
                Route::middleware('permission:export cost of goods')->get('/export', 'export')->name('export'); // Added export
            });

        Route::controller(DirectReceivingController::class)
            ->name('direct-receiving.')
            ->prefix('direct-receiving')
            ->group(function () {
                Route::middleware('permission:view direct receiving')->get('/', 'index')->name('index');
                Route::middleware('permission:create direct receiving')->get('/create', 'create')->name('create');
                Route::middleware('permission:create direct receiving')->post('/store', 'store')->name('store');
                Route::middleware('permission:view direct receiving')->get('/show/{id}', 'show')->name('show'); // Added show
                Route::middleware('permission:edit direct receiving')->post('/update/{id}', 'update')->name('update'); // Added update
                Route::middleware('permission:delete direct receiving')->delete('/destroy/{id}', 'destroy')->name('destroy'); // Added destroy
                Route::middleware('permission:export direct receiving')->get('/export', 'export')->name('export'); // Added export
            });

        Route::controller(UpcomingInventoryController::class)
            ->name('upcoming-inventories.')
            ->prefix('upcoming-inventories')
            ->group(function () {
                Route::middleware('permission:view upcoming inventories')->get('/', 'index')->name('index');
                Route::middleware('permission:export upcoming inventories')->get('/export', 'export')->name('export'); // Added export
            });

        Route::controller(AccountPayableController::class)
            ->name('account-payable.')
            ->prefix('account-payable')
            ->group(function () {
                Route::middleware('permission:view account payable')->get('/', 'index')->name('index');
                Route::middleware('permission:export account payable')->get('/export', 'export')->name('export'); // Added export
            });

        Route::controller(SalesReportController::class)
            ->name('sales-report.')
            ->prefix('sales-report')
            ->group(function () {
                Route::middleware('permission:view sales report')->get('/', 'index')->name('index');
                Route::middleware('permission:export sales report')->get('/export', 'export')->name('export'); // Added export
            });

        Route::controller(CashPullOutController::class)
            ->name('cash-pull-out.')
            ->prefix('cash-pull-out')
            ->group(function () {
                // Assuming permissions for cash pull out
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
                Route::get('/show/{cash_pull_out}', 'show')->name('show');
                Route::post('/update/{id}', 'update')->name('update'); // Added update
                Route::delete('/destroy/{id}', 'destroy')->name('destroy'); // Added destroy
                Route::get('/export', 'export')->name('export'); // Added export
            });

        Route::controller(CashPullOutApprovalController::class)
            ->name('cash-pull-out-approval.')
            ->prefix('cash-pull-out-approval')
            ->group(function () {
                // Assuming permissions for cash pull out approval
                Route::get('/', 'index')->name('index');
                Route::get('/show/{cash_pull_out}', 'show')->name('show');
                Route::put('/approve/{cash_pull_out}', 'approve')->name('approve');
                Route::post('/update/{id}', 'update')->name('update'); // Added update
                Route::delete('/destroy/{id}', 'destroy')->name('destroy'); // Added destroy
                Route::get('/export', 'export')->name('export'); // Added export
            });

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->name('dashboard');

        // User Management Routes
        Route::controller(UserController::class)->prefix('users')->name('users.')->group(function () {
            Route::middleware('permission:view users')->get('/', 'index')->name('index');
            Route::middleware('permission:view user')->get('/show/{user}', 'show')->name('show');
            Route::middleware('permission:create users')->group(function () {
                Route::get('/create', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
            });
            Route::middleware('permission:edit users')->group(function () {
                Route::get('/edit/{user}', 'edit')->name('edit');
                Route::post('/update/{user}', 'update')->name('update');
            });
            Route::middleware('permission:delete users')->delete('/destroy/{user}', 'destroy')->name('destroy');
            Route::middleware('permission:view users')->get('/export', 'export')->name('export');
        });

        // Role Management Routes
        Route::controller(RolesController::class)->prefix('roles')->name('roles.')->group(function () {
            Route::middleware('permission:view roles')->get('/', 'index')->name('index');
            Route::middleware('permission:create roles')->get('/create', 'create')->name('create');
            Route::middleware('permission:create roles')->post('/store', 'store')->name('store');
            Route::middleware('permission:edit roles')->get('/edit/{role}', 'edit')->name('edit');
            Route::middleware('permission:edit roles')->put('/update/{role}', 'update')->name('update');
            Route::middleware('permission:delete roles')->delete('/destroy/{role}', 'destroy')->name('destroy');
            Route::middleware('permission:view roles')->get('/export', 'export')->name('export');
        });

        // MODIFIED: DTS Delivery Schedules Routes - Renamed and expanded
        Route::controller(DeliveryScheduleController::class)->name('dts-delivery-schedules.')->prefix('dts-delivery-schedules')->group(function () {
            Route::middleware('permission:view dts delivery schedules')->get('/', 'index')->name('index');
            Route::middleware('permission:view dts delivery schedule')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:create dts delivery schedules')->group(function () {
                Route::get('/create', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
            });
            Route::middleware('permission:edit dts delivery schedules')->group(function () {
                Route::get('/edit/{id}', 'edit')->name('edit');
                Route::post('/update/{id}', 'update')->name('update');
            });
            Route::middleware('permission:delete dts delivery schedules')->delete('/destroy/{id}', 'destroy')->name('destroy');
            Route::middleware('permission:export dts delivery schedules')->get('/export', 'export')->name('export');
        });

        // DSP Delivery Schedule
        Route::controller(DSPDeliveryScheduleController::class)->name('dsp-delivery-schedules.')->prefix('dsp-delivery-schedules')->group(function () {
            Route::middleware('permission:view dsp delivery schedules')->get('/', 'index')->name('index');
            Route::middleware('permission:edit dsp delivery schedules')->group(function () {
                Route::get('/edit/{id}', 'edit')->name('edit');
                Route::post('/update/{id}', 'update')->name('update');
            });
        });

        Route::controller(OrdersCutoffController::class)->name('orders-cutoff.')->prefix('orders-cutoff')->group(function () {
            Route::middleware('permission:view orders cutoff')->get('/', 'index')->name('index');
            Route::middleware('permission:show orders cutoff')->get('/show/{orders_cutoff}', 'show')->name('show');
            Route::middleware('permission:create orders cutoff')->group(function () {
                Route::get('/create', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
            });
            Route::middleware('permission:edit orders cutoff')->group(function () {
                Route::get('/edit/{orders_cutoff}', 'edit')->name('edit');
                Route::put('/update/{orders_cutoff}', 'update')->name('update');
            });
            Route::middleware('permission:delete orders cutoff')->delete('/destroy/{orders_cutoff}', 'destroy')->name('destroy');
        });

        // DTS Orders
        Route::controller(DTSController::class)->name('dts-orders.')->prefix('dts-orders')->group(function () {
            Route::middleware('permission:view dts orders')->get('/', 'index')->name('index');
            Route::middleware('permission:create dts orders')->get('/create', 'create')->name('create');
            Route::middleware('permission:create dts orders')->post('/store', 'store')->name('store');
            Route::middleware('permission:view dts order')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:edit dts orders')->get('/edit/{id}', 'edit')->name('edit');
            Route::middleware('permission:edit dts orders')->put('/update/{order_number}', 'update')->name('update');
            Route::middleware('permission:export dts orders')->get('/export', 'export')->name('export');

            Route::get('/get-items-by-variant', 'getItemsByVariant')->name('get-items-by-variant');
            Route::get('/get-schedule', 'getSchedule')->name('get-schedule');
        });

        // Store Orders
        Route::controller(StoreOrderController::class)->prefix('store-orders')->name('store-orders.')->group(function () {
            Route::middleware('permission:view store orders')->get('/', 'index')->name('index');
            Route::middleware('permission:view store order')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:create store orders')->group(function () {
                Route::get('/create', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
                Route::post('/store-orders', 'getImportedOrders')->name('imported-file');
            });
            Route::middleware('permission:edit store orders')->group(function () {
                Route::get('/edit/{store_order}', 'edit')->name('edit');
                Route::put('/update/{store_order}', 'update')->name('update');
            });

            Route::middleware('permission:export store orders')->get('/export', 'export')->name('export'); // Corrected permission

            // NEW: Route to download dynamic supplier order template
            Route::get('/store-order-template/{supplierCode}', 'downloadSupplierOrderTemplate')->name('download-supplier-order-template');

            // ROUTE: To fetch supplier items based on supplier code
            Route::get('/get-supplier-items/{supplierCode}', 'getSupplierItems')->name('get-supplier-items');

            Route::get('/available-dates/{supplier_code}', 'getAvailableDatesForSupplier')->name('available-dates');
            Route::get('/get-branches', 'getBranchesForDateAndSupplier')->name('get-branches');
        });

        // Interco Transfers (Store-to-Store)
        Route::controller(IntercoController::class)->name('interco.')->prefix('interco')->middleware(['auth'])->group(function () {
            Route::middleware('permission:view interco requests')->get('/', 'index')->name('index');
            Route::middleware('permission:create interco requests')->get('/create', 'create')->name('create');
            Route::middleware('permission:create interco requests')->post('/', 'store')->name('store');
            Route::middleware('permission:view interco requests')->get('/show/{interco}', 'show')->name('show');
            Route::middleware('permission:edit interco requests')->get('/edit/{interco}', 'edit')->name('edit');
            Route::middleware('permission:edit interco requests')->put('/{interco}', 'update')->name('update');
            Route::middleware('permission:export interco requests')->get('/export', 'export')->name('export');
            // API Routes for item fetching
            Route::middleware('permission:create interco requests')->get('/get-available-items', 'getAvailableItems')->name('get-available-items');
            Route::middleware('permission:create interco requests')->get('/items/search', 'getAvailableItems')->name('items.search');
            Route::middleware('permission:create interco requests')->get('/get-item-details', 'getItemDetails')->name('get-item-details');
            Route::middleware('permission:create interco requests')->get('/branch-inventory', 'getBranchInventory')->name('branch-inventory');

            // Approval actions
            Route::middleware('permission:approve interco requests')->patch('/{interco}/approve', 'approve')->name('approve');
            Route::middleware('permission:approve interco requests')->patch('/{interco}/disapprove', 'disapprove')->name('disapprove');
            Route::middleware('permission:commit interco requests')->patch('/{interco}/commit', 'commit')->name('commit');
            // Note: approve, commit, and receive actions are handled by existing approval and receiving systems
        });

        // Orders Approval
        Route::controller(OrderApprovalController::class)->name('orders-approval.')->group(function () {
            Route::middleware('permission:view orders for approval list')->get('/orders-approval', 'index')->name('index');
            Route::middleware('permission:view order for approval')->get('/orders-approval/show/{id}', 'show')->name('show');
            Route::middleware('permission:approve/decline order request')->group(function () {
                Route::post('/orders-approval/approve', 'approve')->name('approve');
                Route::post('/orders-approval/reject', 'reject')->name('reject');
            });
            // FIX: Removed the middleware check for the export route to resolve the 403 error.
            Route::get('/orders-approval/export', 'export')->name('export');
            // TBD
            // Route::middleware('permission:edit orders for approval')->post('/orders-approval/add-remarks/{id}', 'addRemarks')->name('add-remarks');
        });

        // CS Approval
        Route::controller(CSApprovalController::class)->name('cs-approvals.')->prefix('cs-approvals')->group(function () {
            Route::middleware('permission:view orders for cs approval list')->get('', 'index')->name('index');
            Route::middleware('permission:view order for cs approval')->get('/show/{id}', 'show')->name('show');

            Route::middleware('permission:cs approve/decline order request')->group(function () {
                Route::post('/approve', 'approve')->name('approve');
                Route::post('/reject', 'reject')->name('reject');
            });
            Route::middleware('permission:export orders for cs approval list')->get('/export', 'export')->name('export'); // Added export permission
        });

        // Approved Orders
        Route::controller(OrderReceivingController::class)->name('orders-receiving.')->group(function () {
            Route::middleware('permission:view approved orders')->get('/orders-receiving', 'index')->name('index');
            Route::middleware('permission:view approved order')->get('/orders-receiving/show/{id}', 'show')->name('show');

            Route::middleware('permission:receive orders')->group(function () {
                Route::post('/orders-receiving/receive/{id}', 'receive')->name('receive');
                Route::post('/orders-receiving/add-delivery-receipt-number', 'addDeliveryReceiptNumber')->name('add-delivery-receipt-number');
                Route::put('/orders-receiving/update-delivery-receipt-number/{id}', 'updateDeliveryReceiptNumber')->name('update-delivery-receipt-number');

                Route::post('/orders-receiving/delete-receiving-history/{id}', 'deleteReceiveDateHistory')->name('delete-receiving-history');
                Route::post('/orders-receiving/update-receiving-history', 'updateReceiveDateHistory')->name('update-receiving-history');

                Route::delete('/orders-receiving/delete/{id}', 'destroyDeliveryReceiptNumber')->name('delete-delivery-receipt-number');

                // New route for attaching images
                Route::post('/orders-receiving/{order}/attach-image', 'attachImage')->name('attach-image');

                Route::middleware('permission:export approved orders')->get('/orders-receiving/export', 'export')->name('export'); // Corrected permission

                Route::put('/orders-receiving/confirm-receive/{id}', 'confirmReceive')->name('confirm-receive');
            });
        });

        // Mass Orders
        Route::controller(MassOrdersController::class)->name('mass-orders.')->prefix('mass-orders')->group(function () {
            Route::middleware('permission:view mass orders')->get('/', 'index')->name('index');
            Route::middleware('permission:show mass orders')->get('/show/{id}', 'show')->name('show');
            Route::get('/get-branches', 'getBranchesForDateAndSupplier')->name('get-branches');
            Route::get('/available-dates/{supplier_code}', 'getAvailableDates')->name('mass-orders.available-dates');
            Route::get('/items/{supplier_code}', 'getItems')->name('mass-orders.items');
            Route::get('/available-dates/{supplier_code}', 'getAvailableDates')->name('mass-orders.available-dates');
            Route::get('/items/{supplier_code}', 'getItems')->name('mass-orders.items');
            Route::middleware('permission:create mass orders')->group(function () {
                Route::get('/create', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
            });
            Route::get('/available-dates/{supplier_code}', 'getAvailableDates')->name('available-dates');
            Route::get('/download-template', 'downloadTemplate')->name('download-template');
            Route::post('/upload', 'uploadMassOrder')->name('upload');

            Route::middleware('permission:edit mass orders')->group(function () {
                Route::get('/edit/{id}', 'edit')->name('edit');
                Route::put('/update/{id}', 'update')->name('update');
            });
        });

        // CS Mass Commits
        Route::controller(CSMassCommitsController::class)->name('cs-mass-commits.')->prefix('cs-mass-commits')->group(function () {
            Route::middleware('permission:view cs mass commits')->get('/', 'index')->name('index');
            Route::middleware('permission:export cs mass commits')->get('/export', 'export')->name('export');
            Route::middleware('permission:edit cs mass commits')->post('/confirm-all', 'confirmAll')->name('confirm-all');
            Route::middleware('permission:edit cs mass commits')->post('/update-commit', 'updateCommit')->name('update-commit');
        });

        // DTS Mass Orders
        Route::controller(DTSMassOrdersController::class)->name('dts-mass-orders.')->prefix('dts-mass-orders')->group(function () {
            Route::middleware('permission:view dts mass orders')->get('/', 'index')->name('index');
            Route::middleware('permission:view dts mass orders')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:create dts mass orders')->group(function () {
                Route::get('/create', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
            });
            Route::middleware('permission:edit dts mass orders')->group(function () {
                Route::get('/edit/{id}', 'edit')->name('edit');
                Route::put('/update/{id}', 'update')->name('update');
            });
            Route::middleware('permission:export dts mass orders')->get('/export/{batch_number}', 'export')->name('export');
            Route::middleware('permission:view dts mass orders')->get('/available-dates/{variant}', 'getAvailableDates')->name('get-available-dates');
            Route::middleware('permission:create dts mass orders')->get('/validate-variant/{variant}', 'validateVariant')->name('validate-variant');
            Route::middleware('permission:create dts mass orders')->get('/validate-cutoff/{variant}', 'validateCutoff')->name('validate-cutoff');
        });

        // CS DTS Mass Commits
        Route::controller(CSDTSMassCommitController::class)->name('cs-dts-mass-commits.')->prefix('cs-dts-mass-commits')->group(function () {
            Route::middleware('permission:view cs dts mass commit')->get('/', 'index')->name('index');
            Route::middleware('permission:edit cs dts mass commit')->group(function () {
                Route::get('/edit/{batchNumber}', 'edit')->name('edit');
                Route::put('/update/{batchNumber}', 'update')->name('update');
            });
        });

        // Month End Schedules
        Route::controller(MonthEndScheduleController::class)
            ->prefix('month-end-schedules')
            ->name('month-end-schedules.')
            ->group(function () {
                Route::middleware('permission:view month end schedules')->get('/', 'index')->name('index');
                Route::middleware('permission:create month end schedules')->post('/', 'store')->name('store');
                Route::middleware('permission:edit month end schedules')->put('/{schedule}', 'update')->name('update');
                Route::middleware('permission:delete month end schedules')->delete('/{schedule}', 'destroy')->name('destroy');
                Route::middleware('permission:view month end schedules')->get('/{schedule}/details', 'getDetails')->name('details');
            });

        // Month End Count Execution
        Route::controller(MonthEndCountController::class)
            ->prefix('month-end-count')
            ->name('month-end-count.')
            ->group(function () {
                Route::middleware('permission:perform month end count')->get('/', 'index')->name('index');
                Route::middleware('permission:perform month end count')->get('/download', 'downloadTemplate')->name('download');
                Route::middleware('permission:perform month end count')->post('/upload', 'upload')->name('upload');
                Route::middleware('permission:perform month end count')->get('/review/{schedule}/{branch}', 'review')->name('review');
                Route::middleware('permission:perform month end count')->post('/submit-for-approval/{schedule}/{branch}', 'submitForApproval')->name('submit-for-approval');
                Route::middleware('permission:edit month end count items')->put('/review/{monthEndCountItem}', 'updateReviewItem')->name('update-review-item');
            });

        // Month End Count Approvals
        Route::controller(MonthEndCountApprovalController::class)
            ->prefix('month-end-count-approvals')
            ->name('month-end-count-approvals.')
            ->group(function () {
                Route::middleware('permission:view month end count approvals')->get('/', 'index')->name('index');
                Route::middleware('permission:view month end count approvals')->get('/{schedule_id}/{branch_id}', 'show')->name('show');
                Route::middleware('permission:edit month end count approval items')->put('/items/{monthEndCountItem}', 'updateItem')->name('update-item');
                Route::middleware('permission:approve month end count level 1')->post('/{schedule_id}/{branch_id}/approve-level1', 'approveLevel1')->name('approve-level1');
                Route::middleware('permission:approve month end count level 2')->post('/{schedule_id}/{branch_id}/approve-level2', 'approveLevel2')->name('approve-level2');
            });

        // NEW: MEC Approval 2nd Level
        Route::controller(MECApproval2Controller::class)
            ->prefix('month-end-count-approvals-level2')
            ->name('month-end-count-approvals-level2.')
            ->group(function () {
                Route::middleware('permission:view month end count approvals level 2')->get('/', 'index')->name('index');
                Route::middleware('permission:view month end count approvals level 2')->get('/{schedule_id}/{branch_id}', 'show')->name('show');
                Route::middleware('permission:approve month end count level 2')->post('/{schedule_id}/{branch_id}/approve', 'approveLevel2')->name('approve');
            });

        // Interco Approvals
        Route::controller(IntercoApprovalController::class)->name('interco-approval.')->group(function () {
            Route::middleware('permission:view interco approvals')->get('/interco-approval', 'index')->name('index');
            Route::middleware('permission:view interco approvals')->get('/interco-approval/show/{id}', 'show')->name('show');
            Route::middleware('permission:approve interco requests')->post('/interco-approval/approve', 'approve')->name('approve');
            Route::middleware('permission:approve interco requests')->post('/interco-approval/disapprove', 'disapprove')->name('disapprove');
            Route::middleware('permission:approve interco requests')->post('/interco-approval/update-quantity/{itemId}', 'updateQuantity')->name('update-quantity');
        });

        // Interco Receiving
        Route::controller(IntercoReceivingController::class)->name('interco-receiving.')->prefix('interco-receiving')->group(function () {
            Route::middleware('permission:view interco receiving')->get('/', 'index')->name('index');
            Route::middleware('permission:view interco receiving')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:receive interco requests')->post('/receive/{id}', 'receive')->name('receive');
            Route::middleware('permission:receive interco requests')->post('/attach-image/{id}', 'attachImage')->name('attach-image');
            Route::middleware('permission:export interco receiving')->get('/export', 'export')->name('export');
            Route::post('/update-receiving-history', 'updateReceiveDateHistory')->name('update-receiving-history');
            Route::post('/confirm-receive/{intercoNumber}', 'confirmReceive')->name('confirm-receive');
        });

        // Store Commits
        Route::controller(StoreCommitsController::class)->name('store-commits.')->prefix('store-commits')->group(function () {
            Route::middleware('permission:view store commits')->get('/', 'index')->name('index');
            Route::middleware('permission:view store commits')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:commit store orders')->post('/commit', 'commit')->name('commit');
            Route::middleware('permission:commit store orders')->post('/update-quantity/{itemId}', 'updateCommitQuantity')->name('update-quantity');

            // Test route without permission middleware for debugging
            Route::post('/test-update-quantity/{itemId}', 'updateCommitQuantityTest');

            Route::middleware('permission:export store commits')->get('/export', 'export')->name('export');
        });

        // Approvals
        Route::controller(ReceivingApprovalController::class)->prefix('receiving-approvals')->name('receiving-approvals.')->group(function () {
            Route::middleware('permission:view received orders for approval list')->get('/', 'index')->name('index');
            Route::middleware('permission:view approved order for approval')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:approve received orders')->post('/approve', 'approveReceivedItem')->name('approve-received-item');
            Route::middleware('permission:approve received orders')->post('/decline', 'declineReceivedItem')->name('decline-received-item');
            Route::middleware('permission:export received orders for approval list')->get('/export', 'export')->name('export'); // Added export
        });

        // Approved Received Items
        Route::controller(ApprovedOrderController::class)->name('approved-orders.')->group(function () {
            Route::middleware('permission:view approved received items')->get('/approved-orders', 'index')->name('index');
            Route::middleware('permission:view approved received item')->get('/approved-orders/show/{id}', 'show')->name('show');
            Route::middleware('permission:cancel approved received item')->put('/approved-orders/cancel-approve-status', 'cancelApproveStatus')->name('cancel-approve-status');
            Route::middleware('permission:export approved received items')->get('/approved-orders/export', 'export')->name('export');
        });
        // Store Transactions Approval
        Route::controller(StoreTransactionApprovalController::class)->name('store-transactions-approval.')->prefix('store-transactions-approval')->group(function () {
            Route::middleware('permission:view store transactions approval')->get('', 'index')->name('index'); // Added middleware
            Route::middleware('permission:view store transactions approval')->get('/summary', 'mainIndex')->name('main-index'); // Added middleware
            Route::middleware('permission:view store transactions approval')->get('/show/{store_transaction}', 'show')->name('show'); // Added middleware

            Route::middleware('permission:approve store transactions')->post('/approve-selected-transactions', 'approveSelectedTransactions') // Added middleware
                ->name('approve-selected-transactions');

            Route::middleware('permission:approve store transactions')->post('/approve-all-transactions', 'approveAllTransactions') // Added middleware
                ->name('approve-all-transactions');
            Route::middleware('permission:export store transactions approval')->get('/export', 'export')->name('export'); // Added export
        });

        // Store Transactions
        Route::controller(StoreTransactionController::class)->name('store-transactions.')->prefix('store-transactions')->group(function () {
            Route::middleware('permission:view store transactions')->get('/summary', 'mainIndex')->name('main-index');

            Route::middleware('permission:view store transactions')->get('/', 'index')->name('index');
            Route::middleware('permission:view store transaction')->get('/show/{store_transaction}', 'show')->name('show');

            Route::middleware('permission:create store transactions')->group(function () {
                Route::get('/create/new', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
                Route::post('/import', 'import')->name('import');
            });

            Route::middleware('permission:edit store transactions')->group(function () {
                Route::get('/edit/{store_transaction}', 'edit')->name('edit');
                Route::put('/update/{store_transaction}', 'update')->name('update');
            });

            Route::middleware('permission:export store transactions')->get('export', 'export')->name('export');
            Route::middleware('permission:export store transactions')->get('main-index/export', 'exportMainIndex')->name('export-main-index');
        });

        // Items
        Route::controller(ItemController::class)->name('items.')->group(function () {
            Route::middleware('permission:view items list')->get('/items-list', 'index')->name('index');
            Route::middleware('permission:view item')->get('/items-list/show/{id}', 'show')->name('show');
            Route::middleware('permission:create new items')->group(function () {
                Route::post('/items-list/store', 'store')->name('store');
                Route::get('/items-list/create', 'create')->name('create');
                Route::post('/items-list/import', 'import')->name('import');
            });
            Route::middleware('permission:edit items')->group(function () { // Grouped edit operations
                Route::get('/items-list/edit/{id}', 'edit')->name('edit');
                Route::put('/items-list/update/{id}', 'update')->name('update');
            });
            Route::middleware('permission:delete items')->delete('/items-list/destroy/{id}', 'destroy')->name('destroy');
            Route::middleware('permission:export items list')->get('/items-list/export', 'export')->name('export');
        });

        // SAP Masterlist
        Route::controller(SAPMasterfileController::class)->name('sapitems.')->group(function () {
            Route::middleware('permission:view sapitems list')->get('/sapitems-list', 'index')->name('index');
            Route::middleware('permission:view sapitems list')->get('/sapitems-list/show/{id}', 'show')->name('show');
            Route::middleware('permission:create sapitems')->group(function () {
                Route::post('/sapitems-list/store', 'store')->name('store');
                Route::get('/sapitems-list/create', 'create')->name('create');
                Route::post('/sapitems-list/import', 'import')->name('import');
            });
            Route::middleware('permission:edit sapitems')->group(function () {
                Route::get('/sapitems-list/edit/{id}', 'edit')->name('edit');
                Route::put('/sapitems-list/update/{id}', 'update')->name('update');
            });
            Route::middleware('permission:delete sapitems')->delete('/sapitems-list/destroy/{id}', 'destroy')->name('destroy');
            Route::middleware('permission:export sapitems list')->get('/sapitems-list/export', 'export')->name('export');
        });

        // Supplier Items
        Route::controller(SupplierItemsController::class)->name('SupplierItems.')->group(function () {
            Route::middleware('permission:view SupplierItems list')->get('/SupplierItems-list', 'index')->name('index');
            Route::middleware('permission:view SupplierItems list')->get('/SupplierItems-list/show/{id}', 'show')->name('show');
            Route::get('/SupplierItems-list/details/{supplierItem}', 'getDetailsJson')->name('details.json');
            Route::middleware('permission:create SupplierItems')->group(function () {
                Route::post('/SupplierItems-list/store', 'store')->name('store');
                Route::get('/SupplierItems-list/create', 'create')->name('create');
                Route::post('/SupplierItems-list/import', 'import')->name('import');
                Route::get('/SupplierItems-list/download-skipped-log', 'downloadSkippedImportLog')->name('downloadSkippedImportLog');
            });
            Route::middleware('permission:edit SupplierItems')->group(function () {
                Route::get('/SupplierItems-list/edit/{id}', 'edit')->name('edit');
                Route::put('/SupplierItems-list/update/{id}', 'update')->name('update');
            });
            Route::middleware('permission:delete SupplierItems')->delete('/SupplierItems-list/destroy/{id}', 'destroy')->name('destroy');
            Route::middleware('permission:export SupplierItems list')->get('/SupplierItems-list/export', 'export')->name('export');

            // NEW ROUTE: To fetch specific supplier item details by ItemCode and SupplierCode
            // This route now matches your existing naming convention and prefix.
            Route::get('/SupplierItems-list/details-by-code/{itemCode}/{supplierCode}', 'getDetailsByItemCodeAndSupplierCode')->name('get-details-by-code');
        });

        // POSMasterfile
        Route::controller(POSMasterfileController::class)->name('POSMasterfile.')->group(function () {
            Route::middleware('permission:view POSMasterfile list')->get('/POSMasterfile-list', 'index')->name('index');
            Route::middleware('permission:view POSMasterfile list')->get('/POSMasterfile-list/show/{id}', 'show')->name('show');
            Route::middleware('permission:create POSMasterfile')->group(function () {
                Route::post('/POSMasterfile-list/store', 'store')->name('store');
                Route::get('/POSMasterfile-list/create', 'create')->name('create');
                Route::post('/POSMasterfile-list/import', 'import')->name('import');
            });
            Route::middleware('permission:edit POSMasterfile')->group(function () {
                Route::get('/POSMasterfile-list/edit/{id}', 'edit')->name('edit');
                Route::put('/POSMasterfile-list/update/{id}', 'update')->name('update');
                Route::get('/POSMasterfile-list/product/{id}', 'getProductDetails')->name('product.show');
            });
            Route::middleware('permission:delete POSMasterfile')->delete('/POSMasterfile-list/destroy/{id}', 'destroy')->name('destroy');
            Route::middleware('permission:export POSMasterfile list')->get('/POSMasterfile-list/export', 'export')->name('export');
        });

        // POSMasterfileBOM
        Route::controller(POSMasterfileBOMController::class)->name('pos-bom.')->group(function () {
            Route::middleware('permission:view POSMasterfile BOM list')->get('/pos-bom-list', 'index')->name('index');
            Route::middleware('permission:view POSMasterfile BOM list')->get('/pos-bom-list/show/{posBom}', 'show')->name('show');
            Route::middleware('permission:create POSMasterfile BOM')->group(function () {
                Route::post('/pos-bom-list/store', 'store')->name('store');
                Route::get('/pos-bom-list/create', 'create')->name('create');
                Route::post('/pos-bom-list/import', 'import')->name('import');
                // Route for downloading skipped import log
                Route::get('/pos-bom-list/download-skipped-log', 'downloadSkippedImportLog')->name('downloadSkippedImportLog');
            });
            Route::middleware('permission:edit POSMasterfile BOM')->group(function () {
                Route::get('/pos-bom-list/edit/{posBom}', 'edit')->name('edit');
                Route::put('/pos-bom-list/update/{posBom}', 'update')->name('update');
            });
            Route::middleware('permission:delete POSMasterfile BOM')->delete('/pos-bom-list/destroy/{posBom}', 'destroy')->name('destroy');
            Route::middleware('permission:export POSMasterfile BOM')->get('/pos-bom-list/export', 'export')->name('export');
        });

        // BOM
        Route::controller(MenuController::class)->prefix('menu-list')->name('menu-list.')->group(function () {
            Route::middleware('permission:view bom list')->get('/', 'index')->name('index');
            Route::middleware('permission:view bom')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:create bom')->group(function () {
                Route::post('/store', 'store')->name('store');
                Route::get('/create', 'create')->name('create');
                Route::post('/import', 'import')->name('import');
            });
            Route::middleware('permission:edit bom')->group(function () {
                Route::put('/update/{id}', 'update')->name('update');
                Route::get('/edit/{id}', 'edit')->name('edit');
            });

            Route::middleware('permission:export bom list')->get('/export', 'export')->name('export');

            Route::middleware('permission:delete bom')->delete('/destroy/{id}', 'destroy')->name('destroy');

            Route::middleware('permission:create bom')->post('/import-bom-list', 'importBomList')->name('import-bom-list');

            Route::middleware('permission:create bom')->post('/import-bom-ingredients', 'importBomIngredients')->name('import-bom-ingredients');
        });

        // Stock Management
        Route::controller(StockManagementController::class)->prefix('stock-management')->name('stock-management.')->group(function () {
            Route::middleware('permission:view stock management')->get('/', 'index')->name('index');
            Route::middleware('permission:view stock management history')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:log stock usage')->post('/log-usage', 'logUsage')->name('log-usage');
            Route::middleware('permission:add stock quantity')->post('/add-quantity', 'addQuantity')->name('add-quantity');
            Route::middleware('permission:export stock management')->get('export', 'export')->name('export');


            Route::middleware('permission:export stock management')->get('/export/add', 'exportAdd')->name('export-add');
            Route::middleware('permission:export stock management')->get('/export/log', 'exportLog')->name('export-log');
            Route::middleware('permission:export stock management')->get('/export/soh', 'exportSOH')->name('export-soh');

            Route::middleware('permission:add stock quantity')->post('/import/add', 'importAdd')->name('import-add');

            Route::middleware('permission:log stock usage')->post('/import/log-usage', 'importLogUsage')->name('import-log-usage');

            Route::middleware('permission:create soh adjustment')->post('/import/soh-update', 'importSOHUpdate')->name('import-soh-update');
        });

        // Wastage Record
        Route::controller(WastageController::class)->name('wastage.')->prefix('wastage')->group(function () {
            Route::middleware('permission:view wastage record')->get('/', 'index')->name('index');
            Route::middleware('permission:create wastage record')->group(function () {
                Route::get('/create', 'create')->name('create');
                Route::post('/', 'store')->name('store');
            });
            Route::middleware('permission:view wastage record')->get('/show/{wastage}', 'show')->name('show');
            Route::middleware('permission:view wastage record')->get('/show/by-number/{wastage_no}', 'showByNumber')->name('show.by-number');
            Route::middleware('permission:edit wastage record')->group(function () {
                Route::get('/edit/{wastage}', 'edit')->name('edit');
                Route::put('/{wastage}', 'update')->name('update');
            });
            Route::middleware('permission:delete wastage record')->delete('/{wastage}', 'destroy')->name('destroy');
            Route::middleware('permission:export wastage record')->get('/export', 'export')->name('export');
        });

        // Wastage Approval Level 1
        Route::controller(WastageApprovalLevel1Controller::class)->name('wastage-approval-lvl1.')->prefix('wastage-approval-level1')->group(function () {
            Route::middleware('permission:view wastage approval level 1')->get('/', 'index')->name('index');
            Route::middleware('permission:view wastage approval level 1')->get('/show/{wastage}', 'show')->name('show');
            Route::middleware('permission:approve wastage level 1')->post('/approve', 'approve')->name('approve');
            Route::middleware('permission:approve wastage level 1')->post('/cancel', 'cancel')->name('cancel');
            Route::middleware('permission:edit wastage approval level 1')->post('/update-quantity/{itemId}', 'updateQuantity')->name('update-quantity');
            Route::middleware('permission:delete wastage approval level 1')->delete('/destroy-item/{itemId}', 'destroyItem')->name('destroy-item');
        });

        // Wastage Approval Level 2
        Route::controller(WastageApprovalLevel2Controller::class)->name('wastage-approval-lvl2.')->prefix('wastage-approval-level2')->group(function () {
            Route::middleware('permission:view wastage approval level 2')->get('/', 'index')->name('index');
            Route::middleware('permission:view wastage approval level 2')->get('/show/{wastage}', 'show')->name('show');
            Route::middleware('permission:approve wastage level 2')->post('/approve', 'approve')->name('approve');
            Route::middleware('permission:cancel wastage approval level 2')->post('/cancel', 'cancel')->name('cancel');
            Route::middleware('permission:edit wastage approval level 2')->post('/update-quantity/{itemId}', 'updateQuantity')->name('update-quantity');
            Route::middleware('permission:delete wastage approval level 2')->delete('/destroy-item/{itemId}', 'destroyItem')->name('destroy-item');
        });

        Route::controller(UOMConversionController::class)->name('uom-conversions.')->prefix('uom-conversions')->group(function () {
            Route::middleware('permission:view uom conversions')->get('/', 'index')->name('index');
            Route::middleware('permission:create uom conversion')->post('/import', 'import')->name('import');
            Route::middleware('permission:create uom conversion')->post('/store', 'store')->name('store');
            Route::middleware('permission:edit uom conversion')->post('/update/{id}', 'update')->name('update');
            Route::middleware('permission:delete uom conversion')->delete('/destroy/{id}', 'destroy')->name('destroy');
            Route::middleware('permission:export uom conversions')->get('/export', 'export')->name('export');
        });

        Route::controller(SOHAdjustmentController::class)
            ->prefix('soh-adjustment')
            ->name('soh-adjustment.')
            ->group(function () {
                Route::middleware('permission:view soh adjustment')->get('/', 'index')->name('index');
                Route::middleware('permission:approve soh adjustment')->post('/approveSelectedItems', 'approveSelectedItems')->name('approve-selected-items');
                Route::middleware('permission:export soh adjustment')->get('/export', 'export')->name('export');
            });

        Route::controller(ExcelTemplateController::class)
            ->name('excel.')
            ->prefix('excel')
            ->group(function () {

                Route::get('/gsi-bakery-template', 'gsiBakeryTemplate')
                    ->name('gsi-bakery-template');
                Route::get('/gsi-pr-template', 'gsiPrTemplate')
                    ->name('gsi-pr-template');
                Route::get('/pul-template', 'pulTemplate')
                    ->name('pul-template');

                Route::get('/products-template', 'productsTemplate')
                    ->name('products-template');

                Route::get('/sapmasterfile-template', 'sapMasterfileTemplate')
                    ->name('sapmasterfile-template');

                Route::get('/POSMasterfile-template', 'POSMasterfileTemplate')
                    ->name('POSMasterfile-template');

                Route::get('/SupplierItems-template', 'SupplierItemsTemplate')
                    ->name('SupplierItems-template');

                Route::get('/store-transactions-template', 'storeTransactionsTemplate')
                    ->name('store-transactions-template');

                Route::get('/menu-template', 'menuTemplate')
                    ->name('menu-template');

                Route::get('/fruits-and-vegetables-south-template', 'fruitsAndVegetablesTemplate')
                    ->name('fruits-and-vegetables-south-template');

                Route::get('/fruits-and-vegetables-mm-template', 'fruitsAndVegetablesMMTemplate')
                    ->name('fruits-and-vegetables-mm-template');

                Route::get('/ice-cream-template', 'iceCreamTemplate')
                    ->name('ice-cream-template');

                Route::get('/salmon-template', 'salmonTemplate')
                    ->name('salmon-template');

                Route::get('/wip-list-template', 'wipListTemplate')->name('wip-list-template');

                Route::get('/wip-ingredients-template', 'wipIngredientsTemplate')->name('wip-ingredients-template');

                Route::get('/pos-bom-template', 'posBomTemplate')->name('pos-bom-template');

                Route::get('/bom-list-template', 'bomListTemplate')->name('bom-list-template');

                Route::get('/bom-ingredients-template', 'bomIngredientsTemplate')->name('bom-ingredients-template');

                // NEW: Store Order template route
                Route::get('/store-order-template', 'storeOrderTemplate')->name('store-order-template');
            });

        // Items Order Summary
        Route::controller(ProductOrderSummaryController::class)->name('product-orders-summary.')->group(function () {
            Route::middleware('permission:view items order summary')->get('/product-orders-summary', 'index')->name('index');
            Route::middleware('permission:view items order summary')->get('/product-orders-summary/show/{id}', 'show')->name('show');
            Route::middleware('permission:export items order summary')->get('/product-orders-summary/download-orders-summary-pdf', 'downloadOrdersPdf')->name('export');
        });
        Route::controller(IceCreamOrderController::class)->name('ice-cream-orders.')->prefix('ice-cream-orders')->group(function () {
            Route::middleware('permission:view ice cream orders')->get('/', 'index')->name('index');
            Route::middleware('permission:export ice cream orders')->get('/excel', 'excel')->name('excel');
        });
        Route::controller(SalmonOrderController::class)->name('salmon-orders.')->prefix('salmon-orders')->group(function () {
            Route::middleware('permission:view salmon orders')->get('/', 'index')->name('index');
            Route::middleware('permission:export salmon orders')->get('/excel', 'excel')->name('excel');
        });
        Route::controller(FruitAndVegetableController::class)->prefix('fruits-and-vegetables')->name('fruits-and-vegetables.')->group(function () {
            Route::middleware('permission:view fruits and vegetables orders')->get('/', 'index')->name('index');
            Route::middleware('permission:view fruits and vegetables orders')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:export fruits and vegetables orders')->get('/export', 'export')->name('export');
        });



        // Manage References
        // Each reference sub-category should have its own specific permissions, not a general 'manage references' on the group.
        // The individual routes inside will have their specific permissions.

        Route::controller(CategoryController::class)->prefix('category-list')->name('categories.')->group(function () {
            Route::middleware('permission:view category list')->get('/', 'index')->name('index');
            Route::middleware('permission:create category')->post('/store', 'store')->name('store');
            Route::middleware('permission:edit category')->post('/update/{id}', 'update')->name('update');
            Route::middleware('permission:delete category')->delete('/destroy/{id}', 'destroy')->name('destroy');
            Route::middleware('permission:export category list')->get('/export', 'export')->name('export');
        });

        Route::controller(MenuCategoryController::class)->prefix('menu-categories')->name('menu-categories.')->group(function () {
            Route::middleware('permission:view menu categories')->get('/', 'index')->name('index');
            Route::middleware('permission:create menu category')->get('/create', 'create')->name('create');
            Route::middleware('permission:create menu category')->post('/store', 'store')->name('store');
            Route::middleware('permission:view menu categories')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:edit menu category')->get('/edit/{id}', 'edit')->name('edit');
            Route::middleware('permission:edit menu category')->post('/update/{id}', 'update')->name('update');
            Route::middleware('permission:delete menu category')->delete('/destroy/{id}', 'destroy')->name('destroy');
            Route::middleware('permission:export menu categories')->get('/export', 'export')->name('export');
        });

        Route::controller(InvetoryCategoryController::class)->prefix('inventory-categories')->name('inventory-categories.')->group(function () {
            Route::middleware('permission:view inventory categories')->get('/', 'index')->name('index');
            Route::middleware('permission:create inventory category')->post('/store', 'store')->name('store');
            Route::middleware('permission:edit inventory category')->post('/update/{id}', 'update')->name('update');
            Route::middleware('permission:delete inventory category')->delete('/destroy/{id}', 'destroy')->name('destroy');
            Route::middleware('permission:export inventory categories')->get('/export', 'export')->name('export');
        });

        Route::controller(StoreBranchController::class)->name('branches.')->prefix('branches')->group(function () { // Renamed from store-branches to branches
            Route::middleware('permission:view branches')->get('/', 'index')->name('index');
            Route::middleware('permission:create branch')->get('/create', 'create')->name('create');
            Route::middleware('permission:create branch')->post('/store', 'store')->name('store');
            Route::middleware('permission:view branches')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:edit branch')->get('/edit/{id}', 'edit')->name('edit');
            Route::middleware('permission:edit branch')->post('/update/{id}', 'update')->name('update');
            Route::middleware('permission:delete branch')->delete('/destroy/{id}', 'destroy')->name('destroy');
            Route::middleware('permission:export branches')->get('/export', 'export')->name('export');
        });

        Route::controller(SupplierController::class)->prefix('suppliers')->name('suppliers.')->group(function () {
            Route::middleware('permission:view suppliers')->get('/', 'index')->name('index');
            Route::middleware('permission:create supplier')->get('/create', 'create')->name('create');
            Route::middleware('permission:create supplier')->post('/store', 'store')->name('store');
            Route::middleware('permission:view supplier')->get('/{supplier}', 'show')->name('show'); // This will create a route named 'suppliers.show'
            Route::middleware('permission:edit supplier')->get('/edit/{id}', 'edit')->name('edit');
            Route::middleware('permission:edit supplier')->put('/update/{id}', 'update')->name('update');
            Route::middleware('permission:delete supplier')->delete('/destroy/{id}', 'destroy')->name('destroy');
            Route::middleware('permission:export suppliers')->get('/export', 'export')->name('export');
        });

        Route::controller(UnitOfMeasurementController::class)->name('unit-of-measurements.')->group(function () {
            Route::middleware('permission:view unit of measurements')->get('/unit-of-measurements', 'index')->name('index');
            Route::middleware('permission:create unit of measurement')->post('/unit-of-measurements/store', 'store')->name('store');
            Route::middleware('permission:edit unit of measurement')->post('/unit-of-measurements/update/{id}', 'update')->name('update');
            Route::middleware('permission:delete unit of measurement')->delete('/unit-of-measurements/destroy/{id}', 'destroy')->name('destroy');
            Route::middleware('permission:export unit of measurements')->get('/unit-of-measurements/export', 'export')->name('export');
        });

        Route::controller(CostCenterController::class)->name('cost-centers.')->prefix('cost-centers')->group(function () {
            Route::middleware('permission:view cost centers')->get('/', 'index')->name('index');
            Route::middleware('permission:create cost center')->post('/store', 'store')->name('store');
            Route::middleware('permission:edit cost center')->post('/update/{id}', 'update')->name('update');
            Route::middleware('permission:delete cost center')->delete('/destroy/{id}', 'destroy')->name('destroy');
            Route::middleware('permission:export cost centers')->get('/export', 'export')->name('export');
        });

        // Consolidated Profile Routes
        Route::controller(ProfileController::class)->name('profile.')
            ->prefix('profile')
            ->group(function () {
                Route::get('/', 'index')->name('index'); // Your existing index route
                Route::post('/update-details/{id}', 'updateDetails')->name('update-details');
                Route::post('/update-password/{id}', 'updatePassword')->name('update-password');

                // Standard profile routes moved here
                Route::get('/edit', 'edit')->name('edit');
                Route::patch('/', 'update')->name('update');
                Route::delete('/', 'destroy')->name('destroy');
            });

        Route::controller(TestController::class)->group(function () {
            Route::get('/test', 'index')->name('index'); // Added name for test route
            Route::post('/uploadImage', 'store')->name('upload-image');
            Route::post('/destroy', 'destroy')->name('destroy');
            Route::post('/approveImage/{id}', 'approveImage')->name('approveImage');
        });
    });

require __DIR__ . '/auth.php';
