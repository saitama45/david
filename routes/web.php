<?php

use App\Http\Controllers\AccountPayableController;
use App\Http\Controllers\ApprovedReceivedItem;
use App\Http\Controllers\ApprovedOrderController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CashPullOutApproval;
use App\Http\Controllers\CashPullOutApprovalController;
use App\Http\Controllers\CashPullOutController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CostCenterController;
use App\Http\Controllers\CostOfGoodController;
use App\Http\Controllers\CSApprovalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DaysInventoryOutstanding;
use App\Http\Controllers\DaysPayableOutStanding;
use App\Http\Controllers\DeliveryScheduleController;
use App\Http\Controllers\DirectReceivingController;
use App\Http\Controllers\DTSController;
use App\Http\Controllers\ExcelTemplateController;
use App\Http\Controllers\FruitAndVegetableController;
use App\Http\Controllers\IceCreamOrderController;
use App\Http\Controllers\InventoryReportController;
use App\Http\Controllers\InvetoryCategoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\LowOnStockController;
use App\Http\Controllers\MenuCategoryController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderApprovalController;
use App\Http\Controllers\OrderReceivingController;
use App\Http\Controllers\PDFReportController;
use App\Http\Controllers\PersmissionController;
use App\Http\Controllers\ProductOrderSummaryController;
use App\Http\Controllers\ProductSalesController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\SalmonOrderController;
use App\Http\Controllers\StoreOrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReceivingApprovalController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\SOHAdjustmentController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockManagementController;
use App\Http\Controllers\StoreBranchController;
use App\Http\Controllers\StoreTransactionApprovalController;
use App\Http\Controllers\StoreTransactionController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\Top10InventoriesController;
use App\Http\Controllers\UnitOfMeasurementController;
use App\Http\Controllers\UOMConversionController;
use App\Http\Controllers\UpcomingInventoryController;
use App\Http\Controllers\UsageRecordController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WIPListController;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('jobs', function () {
    dispatch(function () {
        logger('test');
    });
});


Route::middleware('auth')
    ->group(function () {

        Route::controller(WIPListController::class)->name('wip-list.')->prefix('wip-list')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/import-wip-list', 'importWipList')->name('import-wip-list');
            Route::post('/import-wip-ingredients', 'importWipIngredients')->name('import-wip-ingredients');
            Route::get('/show/{id}', 'show')->name('show');
        });

        Route::get('/templates', [TemplateController::class, 'index']);
        Route::post('/template/store', [TemplateController::class, 'store'])->name('templates.store');

        Route::get('/test-report', [PDFReportController::class, 'index']);

        Route::controller(PDFReportController::class)->name('pdf-export.')->prefix('pdf-export')->group(function () {
            Route::get('/store-orders', 'storeOrders')->name('store-orders');
        });

        Route::resource('low-on-stocks', LowOnStockController::class);

        Route::controller(Top10InventoriesController::class)
            ->name('top-10-inventories.')
            ->prefix('top-10-inventories')
            ->group(function () {
                Route::get('/', 'index')->name('index');
            });

        Route::controller(DaysPayableOutStanding::class)
            ->name('days-payable-outstanding.')
            ->prefix('days-payable-outstanding')
            ->group(function () {
                Route::get('/', 'index')->name('index');
            });

        Route::controller(DaysInventoryOutstanding::class)
            ->name('days-inventory-outstanding.')
            ->prefix('days-inventory-outstanding')
            ->group(function () {
                Route::get('/', 'index')->name('index');
            });

        Route::controller(InventoryReportController::class)
            ->name('inventories-report.')
            ->prefix('inventories-report')
            ->group(function () {
                Route::get('/', 'index')->name('index');
            });


        Route::controller(CostOfGoodController::class)
            ->name('cost-of-goods.')
            ->prefix('cost-of-goods')
            ->group(function () {
                Route::get('/', 'index')->name('index');
            });

        Route::controller(DirectReceivingController::class)
            ->name('direct-receiving.')
            ->prefix('direct-receiving')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
            });

        Route::controller(UpcomingInventoryController::class)
            ->name('upcoming-inventories.')
            ->prefix('upcoming-inventories')
            ->group(function () {
                Route::get('/', 'index')->name('index');
            });

        Route::controller(AccountPayableController::class)
            ->name('account-payable.')
            ->prefix('account-payable')
            ->group(function () {
                Route::get('/', 'index')->name('index');
            });

        Route::controller(SalesReportController::class)
            ->name('sales-report.')
            ->prefix('sales-report')
            ->group(function () {
                Route::get('/', 'index')->name('index');
            });

        Route::controller(CashPullOutController::class)
            ->name('cash-pull-out.')
            ->prefix('cash-pull-out')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
                Route::get('/show/{cash_pull_out}', 'show')->name('show');
            });

        Route::controller(CashPullOutApprovalController::class)
            ->name('cash-pull-out-approval.')
            ->prefix('cash-pull-out-approval')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/show/{cash_pull_out}', 'show')->name('show');
                Route::put('/approve/{cash_pull_out}', 'approve')->name('approve');
            });

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->name('dashboard');

        // User
        Route::controller(UserController::class)->name('users.')->group(function () {
            Route::middleware('permission:view users')->get('/users', 'index')->name('index');
            Route::middleware('permission:view user')->get('/users/show/{user}', 'show')->name('show');
            Route::middleware('permission:create users')->group(function () {
                Route::get('/users/create', 'create')->name('create');
                Route::post('/users/store', 'store')->name('store');
            });
            Route::middleware('permission:edit users')->group(function () {
                Route::get('/users/edit/{user}', 'edit')->name('edit');
                Route::post('/users/update/{user}', 'update')->name('update');
            });
            Route::middleware('permission:delete users')->delete('/users/destroy/{user}', 'destroy')->name('destroy');
            Route::middleware('permission:view users')->get('/export', 'export')->name('export');
        });

        // Roles 
        Route::controller(RolesController::class)->name('roles.')->prefix('roles')->group(function () {
            Route::middleware('permission:view roles')->get('/', 'index')->name('index');
            Route::middleware('permission:create roles')->get('/create', 'create')->name('create');
            Route::middleware('permission:create roles')->post('/store', 'store')->name('store');
            Route::middleware('permission:edit roles')->get('/edit/{role}', 'edit')->name('edit');
            Route::middleware('permission:edit roles')->put('/update/{role}', 'update')->name('update');
            Route::middleware('permission:delete roles')->delete('/destroy/{role}', 'destroy')->name('destroy');
            Route::middleware('permission:view roles')->get('/export', 'export')->name('export');
        });

        // DTS Delivery Schedules
        Route::controller(DeliveryScheduleController::class)->name('delivery-schedules.')->prefix('delivery-schedules')->group(function () {
            Route::middleware('permission:view dts delivery schedules')->get('/', 'index')->name('index');
            Route::middleware('permission:edit dts delivery schedules')->get('/edit/{id}', 'edit')->name('edit');
            Route::middleware('permission:edit dts delivery schedules')->post('/update/{id}', 'update')->name('update');
        });

        // DTS Orders
        Route::controller(DTSController::class)->name('dts-orders.')->prefix('dts-orders')->group(function () {
            Route::middleware('permission:view dts orders')->get('/', 'index')->name('index');
            Route::middleware('permission:create dts orders')->get('/create/{variant}', 'create')->name('create');
            Route::middleware('permission:create dts orders')->post('/store', 'store')->name('store');
            Route::middleware('permission:view dts order')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:edit dts orders')->get('/edit/{id}', 'edit')->name('edit');
            Route::middleware('permission:edit dts orders')->put('/update/{store_order}', 'update')->name('update');

            Route::get('/export', 'export')->name('export');
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

            Route::middleware('permission:view dts orders')->get('/export', 'export')->name('export');
        });

        // Orders Approval
        Route::controller(OrderApprovalController::class)->name('orders-approval.')->group(function () {
            Route::middleware('permission:view orders for approval list')->get('/orders-approval', 'index')->name('index');
            Route::middleware('permission:view order for approval')->get('/orders-approval/show/{id}', 'show')->name('show');
            Route::middleware('permission:approve/decline order request')->group(function () {
                Route::post('/orders-approval/approve', 'approve')->name('approve');
                Route::post('/orders-approval/reject', 'reject')->name('reject');
            });
            Route::middleware('permission:view orders for approval list')->get('/orders-approval/export', 'export')->name('export');

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
            Route::middleware('permission:view orders for cs approval list')->get('/export', 'export')->name('export');
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


                Route::middleware('permission:view approved orders')->get('/orders-receiving/export', 'export')->name('export');

                Route::put('/orders-receiving/confirm-receive/{id}', 'confirmReceive')->name('confirm-receive');
            });
        });

        // Approvals 
        Route::controller(ReceivingApprovalController::class)->prefix('receiving-approvals')->name('receiving-approvals.')->group(function () {
            Route::middleware('permission:view received orders for approval list')->get('/', 'index')->name('index');
            Route::middleware('permission:view approved order for approval')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:approve received orders')->post('/approve', 'approveReceivedItem')->name('approve-received-item');
            Route::middleware('permission:approve received orders')->post('/decline', 'declineReceivedItem')->name('decline-received-item');
        });

        // Approved Received Items
        Route::controller(ApprovedOrderController::class)->name('approved-orders.')->group(function () {
            Route::middleware('permission:view approved received items')->get('/approved-orders', 'index')->name('index');
            Route::middleware('permission:view approved received item')->get('/approved-orders/show/{id}', 'show')->name('show');
            Route::middleware('permission:view approved received item')->put('/approved-orders/cancel-approve-status', 'cancelApproveStatus')->name('cancel-approve-status');
            Route::middleware('permission:view approved received items')->get('/approved-orders/export', 'export')->name('export');
        });
        // Store Transactions Approval
        Route::controller(StoreTransactionApprovalController::class)->name('store-transactions-approval.')->prefix('store-transactions-approval')->group(function () {
            Route::get('', 'index')->name('index');
            Route::middleware('permission:view store transactions')->get('/summary', 'mainIndex')->name('main-index');
            Route::get('/show/{store_transaction}', 'show')->name('show');

            Route::post('/approve-selected-transactions', 'approveSelectedTransactions')
                ->name('approve-selected-transactions');

            Route::post('/approve-all-transactions', 'approveAllTransactions')
                ->name('approve-all-transactions');
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

            Route::middleware('permission:view store transactions')->get('export', 'export')->name('export');
            Route::middleware('permission:view store transactions')->get('main-index/export', 'exportMainIndex')->name('export-main-index');
        });

        // Items
        Route::controller(ItemController::class)->name('items.')->group(function () {
            Route::middleware('permission:view items list')->get('/items-list', 'index')->name('index');
            Route::middleware('permission:view item')->get('/items-list/show/{id}', 'show')->name('show');
            Route::middleware('permission:create new items')->group(function () {
                Route::post('/items-list/store', 'store')->name('store');
                Route::get('/items-list/create', 'create')->name('create');
                Route::post('/items-list/import', 'import')->name('import');

                Route::get('/items-list/edit/{id}', 'edit')->name('edit');
                Route::put('/items-list/update/{id}', 'update')->name('update');

                Route::delete('/items-list/destroy/{id}', 'destroy')->name('destroy');

                Route::get('/items-list/export', 'export')->name('export');
            });
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

            Route::get('/export', 'export')->name('export');

            Route::middleware('permission:delete bom')->delete('/destroy/{id}', 'destroy')->name('destroy');
        });

        // Stock Management 
        Route::controller(StockManagementController::class)->prefix('stock-management')->name('stock-management.')->group(function () {
            Route::middleware('permission:view stock management')->get('/', 'index')->name('index');
            Route::middleware('permission:view stock management history')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:log stock usage')->post('/log-usage', 'logUsage')->name('log-usage');
            Route::middleware('permission:add stock quantity')->post('/add-quantity', 'addQuantity')->name('add-quantity');
            Route::middleware('permission:view stock management')->get('export', 'export')->name('export');


            Route::get('/export/add', 'exportAdd')->name('export-add');
            Route::get('/export/log', 'exportLog')->name('export-log');
            Route::get('/export/soh', 'exportSOH')->name('export-soh');

            Route::post('/import/add', 'importAdd')->name('import-add');

            Route::post('/import/log-usage', 'importLogUsage')->name('import-log-usage');

            Route::post('/import/soh-update', 'importSOHUpdate')->name('import-soh-update');
        });

        Route::controller(UOMConversionController::class)->name('uom-conversions.')->prefix('uom-conversions')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/import', 'import')->name('import');
        });

        Route::controller(SOHAdjustmentController::class)
            ->prefix('soh-adjustment')
            ->name('soh-adjustment.')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/approveSelectedItems', 'approveSelectedItems')->name('approve-selected-items');
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

                Route::get('/store-transactions-template', 'storeTransactionsTemplate')
                    ->name('store-transactions-template');

                Route::get('/menu-template', 'menuTemplate')
                    ->name('menu-template');

                Route::get('/fruits-and-vegetables-template', 'fruitsAndVegetablesTemplate')
                    ->name('fruits-and-vegetables-template');

                Route::get('/ice-cream-template', 'iceCreamTemplate')
                    ->name('ice-cream-template');

                Route::get('/salmon-template', 'salmonTemplate')
                    ->name('salmon-template');

                Route::get('/wip-list-template', 'wipListTemplate')->name('wip-list-template');

                Route::get('/wip-ingredients-template', 'wipIngredientsTemplate')->name('wip-ingredients-template');
            });

        // Items Order Summary
        Route::middleware('permission:view items order summary')->controller(ProductOrderSummaryController::class)->name('product-orders-summary.')->group(function () {
            Route::get('/product-orders-summary', 'index')->name('index');
            Route::get('/product-orders-summary/show/{id}', 'show')->name('show');
            Route::get('/product-orders-summary/download-orders-summary-pdf', 'downloadOrdersPdf')->name('export');
        });
        Route::middleware('permission:view ice cream orders')->controller(IceCreamOrderController::class)->name('ice-cream-orders.')->prefix('ice-cream-orders')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/excel', 'excel')->name('excel');
        });
        Route::middleware('permission:view salmon orders')->controller(SalmonOrderController::class)->name('salmon-orders.')->prefix('salmon-orders')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/excel', 'excel')->name('excel');
        });
        Route::middleware('permission:view fruits and vegetables orders')->controller(FruitAndVegetableController::class)->prefix('fruits-and-vegetables')->name('fruits-and-vegetables.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/show/{id}', 'show')->name('show');
            Route::get('/export', 'export')->name('export');
        });



        // Manage References
        Route::middleware('permission:manage references')->group(function () {

            Route::controller(CategoryController::class)->prefix('category-list')->name('categories.')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/update/{id}', 'update')->name('update');

                Route::post('/store', 'store')->name('store');

                Route::delete('/destroy/{id}', 'destroy')->name('destroy');

                Route::get('/export', 'export')->name('export');
            });

            Route::controller(MenuCategoryController::class)->prefix('menu-categories')->name('menu-categories.')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
                Route::get('/show/{id}', 'show')->name('show');
                Route::get('/edit/{id}', 'edit')->name('edit');
                Route::post('/update/{id}', 'update')->name('update');
                Route::delete('/destroy/{id}', 'destroy')->name('destroy');
                Route::get('/export', 'export')->name('export');
            });

            Route::controller(InvetoryCategoryController::class)->prefix('inventory-categories')->name('inventory-categories.')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/update/{id}', 'update')->name('update');
                Route::post('/store', 'store')->name('store');
                Route::delete('/destroy/{id}', 'destroy')->name('destroy');
                Route::get('/export', 'export')->name('export');
            });

            Route::controller(StoreBranchController::class)->name('store-branches.')->prefix('store-branches')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
                Route::get('/show/{id}', 'show')->name('show');
                Route::get('/edit/{id}', 'edit')->name('edit');
                Route::post('/update/{id}', 'update')->name('update');
                Route::delete('/destroy/{id}', 'destroy')->name('destroy');
                Route::get('/export', 'export')->name('export');
            });

            Route::controller(SupplierController::class)->prefix('suppliers')->name('suppliers.')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
                Route::get('/edit/{id}', 'edit')->name('edit');
                Route::put('/update/{id}', 'update')->name('update');
                Route::delete('/destroy/{id}', 'destroy')->name('destroy');

                Route::get('/export', 'export')->name('export');
            });

            Route::controller(UnitOfMeasurementController::class)->name('unit-of-measurements.')->group(function () {
                Route::get('/unit-of-measurements', 'index')->name('index');
                Route::post('/unit-of-measurements/update/{id}', 'update')->name('update');
                Route::get('/unit-of-measurements/export', 'export')->name('export');
            });

            Route::controller(CostCenterController::class)->name('cost-centers.')->prefix('cost-centers')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/store', 'store')->name('store');
                Route::post('/update/{id}', 'update')->name('update');
                Route::delete('/destroy/{id}', 'destroy')->name('destroy');

                Route::get('/export', 'export')->name('export');
            });
        });

        // Route::get('/audits', [AuditController::class, 'index']);


        // Route::controller(ProductSalesController::class)->prefix('product-sales')->name('product-sales.')->group(function () {
        //     Route::get('/', 'index')->name('index');
        //     Route::get('/show/{id}', 'show')->name('show');
        // });

        // Route::controller(StockController::class)->name('stocks.')
        //     ->group(function () {
        //         Route::get('/stocks', 'index')->name('index');
        //         Route::get('/stocks/show/{id}', 'show')->name('show');
        //     });
        // // TBD
        // Route::controller(UsageRecordController::class)
        //     ->prefix('usage-records')
        //     ->name('usage-records.')
        //     ->group(function () {
        //         Route::get('/', 'index')->name('index');
        //         Route::get('/create', 'create')->name('create');
        //         Route::post('/store', 'store')->name('store');
        //         Route::get('/show/{id}', 'show')->name('show');
        //         Route::post('/import', 'import')->name('import');
        //         Route::delete('/destroy/{id}', 'destroy')->name('destroy');
        //     });


        // Route::controller(PersmissionController::class)->name('permissions.')->prefix('permissions')->group(function () {
        //     Route::get('/', 'index')->name('index');
        //     Route::get('/create', 'create')->name('create');
        //     Route::post('/store', 'store')->name('store');
        //     Route::get('/edit/{id}', 'edit')->name('edit');
        // });

        // Route::controller(SalesOrderController::class)
        //     ->prefix('sales-orders')
        //     ->name('sales-orders.')
        //     ->group(function () {
        //         Route::get('/', 'index')->name('index');
        //         Route::get('/create', 'create')->name('create');
        //         Route::get('/show/{id}', 'show')->name('show');
        //     });


        Route::controller(ProfileController::class)->name('profile.')
            ->prefix('profile')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/update-details/{id}', 'updateDetails')->name('update-details');
                Route::post('/update-password/{id}', 'updatePassword')->name('update-password');
            });

        Route::controller(TestController::class)->group(function () {
            Route::get('/test', 'index')->name('test');
            Route::post('/uploadImage', 'store')->name('upload-image');
            Route::post('/destroy', 'destroy')->name('destroy');
            Route::post('/approveImage/{id}', 'approveImage')->name('approveImage');
        });







        // Route::get('/profile', [ProfileController::class, 'edit'])
        //     ->name('profile.edit');

        // Route::patch('/profile', [ProfileController::class, 'update'])
        //     ->name('profile.update');

        // Route::delete('/profile', [ProfileController::class, 'destroy'])
        //     ->name('profile.destroy');
    });

require __DIR__ . '/auth.php';
