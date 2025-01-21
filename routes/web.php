<?php

use App\Http\Controllers\ApprovedReceivedItem;
use App\Http\Controllers\ApprovedOrderController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CostCenterController;
use App\Http\Controllers\CSApprovalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryScheduleController;
use App\Http\Controllers\DTSController;
use App\Http\Controllers\ExcelTemplateController;
use App\Http\Controllers\FruitAndVegetableController;
use App\Http\Controllers\IceCreamOrderController;
use App\Http\Controllers\InvetoryCategoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\MenuCategoryController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderApprovalController;
use App\Http\Controllers\OrderReceivingController;
use App\Http\Controllers\PersmissionController;
use App\Http\Controllers\ProductOrderSummaryController;
use App\Http\Controllers\ProductSalesController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\SalmonOrderController;
use App\Http\Controllers\StoreOrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReceivingApprovalController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockManagementController;
use App\Http\Controllers\StoreBranchController;
use App\Http\Controllers\StoreTransactionController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\UnitOfMeasurementController;
use App\Http\Controllers\UsageRecordController;
use App\Http\Controllers\UserController;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')
    ->group(function () {
        Route::get('/testonly', function () {
            return 'hello there';
        });
        // Roles 
        Route::controller(RolesController::class)->name('roles.')->prefix('roles')->group(function () {
            Route::middleware('permission:view roles')->get('/', 'index')->name('index');
            Route::middleware('permission:create roles')->get('/create', 'create')->name('create');
            Route::middleware('permission:create roles')->post('/store', 'store')->name('store');
            Route::middleware('permission:edit roles')->get('/edit/{id}', 'edit')->name('edit');
            Route::middleware('permission:edit roles')->put('/update/{id}', 'update')->name('update');

            Route::middleware('permission:edit roles')->delete('/destroy/{id}', 'destroy')->name('destroy');

            Route::get('/export', 'export')->name('export');
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
            Route::middleware('permission:edit dts orders')->put('/update/{id}', 'update')->name('update');

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
                Route::get('/edit/{id}', 'edit')->name('edit');
                Route::put('/update/{id}', 'update')->name('update');
            });

            Route::get('/export', 'export')->name('export');
        });

        // Orders Approval
        Route::controller(OrderApprovalController::class)->name('orders-approval.')->group(function () {
            Route::middleware('permission:view orders for approval list')->get('/orders-approval', 'index')->name('index');
            Route::middleware('permission:view order for approval')->get('/orders-approval/show/{id}', 'show')->name('show');
            Route::middleware('permission:approve/decline order request')->group(function () {
                Route::post('/orders-approval/approve', 'approve')->name('approve');
                Route::post('/orders-approval/reject', 'reject')->name('reject');
            });

            Route::get('/orders-approval/export', 'export')->name('export');

            // TBD
            // Route::middleware('permission:edit orders for approval')->post('/orders-approval/add-remarks/{id}', 'addRemarks')->name('add-remarks');
        });


        Route::controller(CSApprovalController::class)->name('cs-approvals.')->prefix('cs-approvals')->group(function () {
            Route::get('', 'index')->name('index');
            Route::get('/show/{id}', 'show')->name('show');
            Route::post('/approve', 'approve')->name('approve');
            Route::post('/reject', 'reject')->name('reject');

            Route::get('/export', 'export')->name('export');
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
        });

        // Store Transactions 
        Route::controller(StoreTransactionController::class)->name('store-transactions.')->prefix('store-transactions')->group(function () {
            Route::middleware('permission:view store transactions')->get('/', 'index')->name('index');
            Route::middleware('permission:view store transaction')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:create store transactions')->get('/create', 'create')->name('create');
            Route::get('/edit/{id}', 'edit')->name('edit');
            Route::put('/update/{id}', 'update')->name('update');
            Route::post('/store', 'store')->name('store');
            Route::post('/import', 'import')->name('import');
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
            });
        });

        // Menu
        Route::controller(MenuController::class)->prefix('menu-list')->name('menu-list.')->group(function () {
            Route::middleware('permission:view menu list')->get('/', 'index')->name('index');
            Route::middleware('permission:view menu')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:create menu')->group(function () {
                Route::post('/store', 'store')->name('store');
                Route::get('/create', 'create')->name('create');
                Route::post('/import', 'import')->name('import');
            });
            Route::middleware('permission:edit menu')->group(function () {
                Route::put('/update/{id}', 'update')->name('update');
                Route::get('/edit/{id}', 'edit')->name('edit');
            });

            Route::delete('/destroy/{id}', 'destroy')->name('destroy');
        });

        // Stock Management 
        Route::controller(StockManagementController::class)->prefix('stock-management')->name('stock-management.')->group(function () {
            Route::middleware('permission:view stock management')->get('/', 'index')->name('index');
            Route::middleware('permission:view stock management history')->get('/show/{id}', 'show')->name('show');
            Route::middleware('permission:log stock usage')->post('/log-usage', 'logUsage')->name('log-usage');
            Route::middleware('permission:add stock quantity')->post('/add-quantity', 'addQuantity')->name('add-quantity');
        });

        // Items Order Summary
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->name('dashboard');

        Route::get('/test', [DashboardController::class, 'test'])
            ->name('test');


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
            });

        // Items Order Summary
        Route::middleware('permission:view items order summary')->controller(ProductOrderSummaryController::class)->name('product-orders-summary.')->group(function () {
            Route::get('/product-orders-summary', 'index')->name('index');
            Route::get('/product-orders-summary/show/{id}', 'show')->name('show');
            Route::get('/product-orders-summary/download-orders-summary-pdf', 'downloadOrdersPdf')->name('download-orders-summary-pdf');
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

        // User
        Route::controller(UserController::class)->name('users.')->group(function () {
            Route::middleware('permission:view users')->get('/users', 'index')->name('index');
            Route::middleware('permission:view user')->get('/users/show/{id}', 'show')->name('show');
            Route::middleware('permission:create users')->group(function () {
                Route::get('/users/create', 'create')->name('create');
                Route::post('/users/store', 'store')->name('store');
            });
            Route::middleware('permission:edit users')->group(function () {
                Route::get('/users/edit/{id}', 'edit')->name('edit');
                Route::post('/users/update/{id}', 'update')->name('update');
            });

            Route::delete('/users/destroy/{id}', 'destroy')->name('destroy');

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
            });
        });

        Route::get('/audits', [AuditController::class, 'index']);


        Route::controller(ProductSalesController::class)->prefix('product-sales')->name('product-sales.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/show/{id}', 'show')->name('show');
        });

        Route::controller(StockController::class)->name('stocks.')
            ->group(function () {
                Route::get('/stocks', 'index')->name('index');
                Route::get('/stocks/show/{id}', 'show')->name('show');
            });
        // TBD
        Route::controller(UsageRecordController::class)
            ->prefix('usage-records')
            ->name('usage-records.')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
                Route::get('/show/{id}', 'show')->name('show');
                Route::post('/import', 'import')->name('import');
                Route::delete('/destroy/{id}', 'destroy')->name('destroy');
            });


        Route::controller(PersmissionController::class)->name('permissions.')->prefix('permissions')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/store', 'store')->name('store');
            Route::get('/edit/{id}', 'edit')->name('edit');
        });

        Route::controller(SalesOrderController::class)
            ->prefix('sales-orders')
            ->name('sales-orders.')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::get('/show/{id}', 'show')->name('show');
            });


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

        Route::controller(CostCenterController::class)->name('cost-centers.')->prefix('cost-centers')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/store', 'store')->name('store');
            Route::post('/update/{id}', 'update')->name('update');
            Route::delete('/destroy/{id}', 'destroy')->name('destroy');

            Route::get('/export', 'export')->name('export');
        });





        // Route::get('/profile', [ProfileController::class, 'edit'])
        //     ->name('profile.edit');

        // Route::patch('/profile', [ProfileController::class, 'update'])
        //     ->name('profile.update');

        // Route::delete('/profile', [ProfileController::class, 'destroy'])
        //     ->name('profile.destroy');
    });

require __DIR__ . '/auth.php';


// use App\Http\Controllers\ApprovedReceivedItem;
// use App\Http\Controllers\ApprovedOrderController;
// use App\Http\Controllers\AuditController;
// use App\Http\Controllers\CategoryController;
// use App\Http\Controllers\DashboardController;
// use App\Http\Controllers\DeliveryScheduleController;
// use App\Http\Controllers\DTSController;
// use App\Http\Controllers\ExcelTemplateController;
// use App\Http\Controllers\FruitAndVegetableController;
// use App\Http\Controllers\IceCreamOrderController;
// use App\Http\Controllers\InvetoryCategoryController;
// use App\Http\Controllers\ItemController;
// use App\Http\Controllers\MenuCategoryController;
// use App\Http\Controllers\MenuController;
// use App\Http\Controllers\OrderApprovalController;
// use App\Http\Controllers\OrderReceivingController;
// use App\Http\Controllers\PersmissionController;
// use App\Http\Controllers\ProductOrderSummaryController;
// use App\Http\Controllers\ProductSalesController;
// use App\Http\Controllers\SalesOrderController;
// use App\Http\Controllers\SalmonOrderController;
// use App\Http\Controllers\StoreOrderController;
// use App\Http\Controllers\ProfileController;
// use App\Http\Controllers\ReceivingApprovalController;
// use App\Http\Controllers\RolesController;
// use App\Http\Controllers\StockController;
// use App\Http\Controllers\StockManagementController;
// use App\Http\Controllers\StoreBranchController;
// use App\Http\Controllers\StoreTransactionController;
// use App\Http\Controllers\SupplierController;
// use App\Http\Controllers\TestController;
// use App\Http\Controllers\UnitOfMeasurementController;
// use App\Http\Controllers\UsageRecordController;
// use App\Http\Controllers\UserController;
// use App\Models\StoreBranch;
// use App\Models\StoreTransaction;
// use Illuminate\Support\Facades\Route;

// Route::redirect('/', '/dashboard');

// Route::middleware('auth')
//     ->group(function () {
//         Route::get('/testonly', function () {
//             return 'hello there';
//         });
//         // Roles 
//         Route::controller(RolesController::class)->name('roles.')->prefix('roles')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::get('/create', 'create')->name('create');
//             Route::post('/store', 'store')->name('store');
//             Route::get('/edit/{id}', 'edit')->name('edit');
//             Route::post('/update/{id}', 'update')->name('update');
//         });

//         // DTS Delivery Schedules
//         Route::controller(DeliveryScheduleController::class)->name('delivery-schedules.')->prefix('delivery-schedules')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::get('/edit/{id}', 'edit')->name('edit');
//             Route::post('/update/{id}', 'update')->name('update');
//         });

//         // DTS Orders
//         Route::controller(DTSController::class)->name('dts-orders.')->prefix('dts-orders')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::get('/create/{variant}', 'create')->name('create');
//             Route::post('/store', 'store')->name('store');
//             Route::get('/show/{id}', 'show')->name('show');
//             Route::get('/edit/{id}', 'edit')->name('edit');
//             Route::put('/update/{id}', 'update')->name('update');
//         });

//         // Store Orders
//         Route::controller(StoreOrderController::class)->prefix('store-orders')->name('store-orders.')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::get('/show/{id}', 'show')->name('show');
//             Route::get('/create', 'create')->name('create');
//             Route::post('/store', 'store')->name('store');
//             Route::post('/store-orders', 'getImportedOrders')->name('imported-file');
//             Route::get('/edit/{id}', 'edit')->name('edit');
//             Route::put('/update/{id}', 'update')->name('update');
//         });

//         // Orders Approval
//         Route::controller(OrderApprovalController::class)->name('orders-approval.')->group(function () {
//             Route::get('/orders-approval', 'index')->name('index');
//             Route::get('/orders-approval/show/{id}', 'show')->name('show');
//             Route::post('/orders-approval/approve', 'approve')->name('approve');
//             Route::post('/orders-approval/reject', 'reject')->name('reject');
//         });

//         // Approved Orders
//         Route::controller(OrderReceivingController::class)->name('orders-receiving.')->group(function () {
//             Route::get('/orders-receiving', 'index')->name('index');
//             Route::get('/orders-receiving/show/{id}', 'show')->name('show');
//             Route::post('/orders-receiving/receive/{id}', 'receive')->name('receive');
//             Route::post('/orders-receiving/add-delivery-receipt-number', 'addDeliveryReceiptNumber')->name('add-delivery-receipt-number');
//             Route::put('/orders-receiving/update-delivery-receipt-number/{id}', 'updateDeliveryReceiptNumber')->name('update-delivery-receipt-number');
//             Route::post('/orders-receiving/delete-receiving-history/{id}', 'deleteReceiveDateHistory')->name('delete-receiving-history');
//             Route::post('/orders-receiving/update-receiving-history', 'updateReceiveDateHistory')->name('update-receiving-history');
//             Route::delete('/orders-receiving/delete/{id}', 'destroyDeliveryReceiptNumber')->name('delete-delivery-receipt-number');
//         });

//         // Approvals 
//         Route::controller(ReceivingApprovalController::class)->prefix('receiving-approvals')->name('receiving-approvals.')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::get('/show/{id}', 'show')->name('show');
//             Route::post('/approve', 'approveReceivedItem')->name('approve-received-item');
//         });

//         // Approved Received Items
//         Route::controller(ApprovedOrderController::class)->name('approved-orders.')->group(function () {
//             Route::get('/approved-orders', 'index')->name('index');
//             Route::get('/approved-orders/show/{id}', 'show')->name('show');
//         });

//         // Store Transactions 
//         Route::controller(StoreTransactionController::class)->name('store-transactions.')->prefix('store-transactions')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::get('/show/{id}', 'show')->name('show');
//             Route::get('/create', 'create')->name('create');
//         });

//         // Items
//         Route::controller(ItemController::class)->name('items.')->group(function () {
//             Route::get('/items-list', 'index')->name('index');
//             Route::get('/items-list/show/{id}', 'show')->name('show');
//             Route::post('/items-list/store', 'store')->name('store');
//             Route::get('/items-list/create', 'create')->name('create');
//             Route::post('/items-list/import', 'import')->name('import');
//         });

//         // Menu
//         Route::controller(MenuController::class)->prefix('menu-list')->name('menu-list.')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::get('/show/{id}', 'show')->name('show');
//             Route::post('/store', 'store')->name('store');
//             Route::get('/create', 'create')->name('create');
//             Route::put('/update/{id}', 'update')->name('update');
//             Route::get('/edit/{id}', 'edit')->name('edit');
//         });

//         // Stock Management 
//         Route::controller(StockManagementController::class)->prefix('stock-management')->name('stock-management.')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::get('/show/{id}', 'show')->name('show');
//             Route::post('/log-usage', 'logUsage')->name('log-usage');
//             Route::post('/add-quantity', 'addQuantity')->name('add-quantity');
//         });

//         // Dashboard
//         Route::get('/dashboard', [DashboardController::class, 'index'])
//             ->name('dashboard');

//         Route::get('/test', [DashboardController::class, 'test'])
//             ->name('test');

//         // Excel Templatese
//         Route::controller(ExcelTemplateController::class)
//             ->name('excel.')
//             ->prefix('excel')
//             ->group(function () {
//                 Route::get('/gsi-bakery-template', 'gsiBakeryTemplate')->name('gsi-bakery-template');
//                 Route::get('/gsi-pr-template', 'gsiPrTemplate')->name('gsi-pr-template');
//                 Route::get('/pul-template', 'pulTemplate')->name('pul-template');
//                 Route::get('/products-template', 'productsTemplate')->name('products-template');
//             });

//         // Items Order Summary
//         Route::controller(ProductOrderSummaryController::class)->name('product-orders-summary.')->group(function () {
//             Route::get('/product-orders-summary', 'index')->name('index');
//             Route::get('/product-orders-summary/show/{id}', 'show')->name('show');
//             Route::get('/product-orders-summary/download-orders-summary-pdf', 'downloadOrdersPdf')->name('download-orders-summary-pdf');
//         });

//         Route::controller(IceCreamOrderController::class)->name('ice-cream-orders.')->prefix('ice-cream-orders')->group(function () {
//             Route::get('/', 'index')->name('index');
//         });

//         Route::controller(SalmonOrderController::class)->name('salmon-orders.')->prefix('salmon-orders')->group(function () {
//             Route::get('/', 'index')->name('index');
//         });

//         Route::controller(FruitAndVegetableController::class)->prefix('fruits-and-vegetables')->name('fruits-and-vegetables.')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::get('/show/{id}', 'show')->name('show');
//         });

//         // User
//         Route::controller(UserController::class)->name('users.')->group(function () {
//             Route::get('/users', 'index')->name('index');
//             Route::get('/users/show/{id}', 'show')->name('show');
//             Route::get('/users/create', 'create')->name('create');
//             Route::post('/users/store', 'store')->name('store');
//             Route::get('/users/edit/{id}', 'edit')->name('edit');
//             Route::post('/users/update/{id}', 'update')->name('update');
//         });

//         // References Management
//         Route::controller(CategoryController::class)->prefix('category-list')->name('categories.')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::post('/update/{id}', 'update')->name('update');
//             Route::post('/store', 'store')->name('store');
//             Route::delete('/destroy/{id}', 'destroy')->name('destroy');
//         });

//         Route::controller(MenuCategoryController::class)->prefix('menu-categories')->name('menu-categories.')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::get('/create', 'create')->name('create');
//             Route::post('/store', 'store')->name('store');
//             Route::get('/show/{id}', 'show')->name('show');
//             Route::get('/edit/{id}', 'edit')->name('edit');
//             Route::post('/update/{id}', 'update')->name('update');
//         });

//         Route::controller(InvetoryCategoryController::class)->prefix('inventory-categories')->name('inventory-categories.')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::post('/update/{id}', 'update')->name('update');
//             Route::post('/store', 'store')->name('store');
//         });

//         Route::controller(StoreBranchController::class)->name('store-branches.')->prefix('store-branches')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::get('/create', 'create')->name('create');
//             Route::post('/store', 'store')->name('store');
//             Route::get('/show/{id}', 'show')->name('show');
//             Route::get('/edit/{id}', 'edit')->name('edit');
//             Route::post('/update/{id}', 'update')->name('update');
//         });

//         Route::controller(SupplierController::class)->prefix('suppliers')->name('suppliers.')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::get('/create', 'create')->name('create');
//             Route::post('/store', 'store')->name('store');
//             Route::get('/edit/{id}', 'edit')->name('edit');
//             Route::put('/update/{id}', 'update')->name('update');
//         });

//         Route::controller(UnitOfMeasurementController::class)->name('unit-of-measurements.')->group(function () {
//             Route::get('/unit-of-measurements', 'index')->name('index');
//             Route::post('/unit-of-measurements/update/{id}', 'update')->name('update');
//         });

//         Route::get('/audits', [AuditController::class, 'index']);


//         Route::controller(ProductSalesController::class)->prefix('product-sales')->name('product-sales.')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::get('/show/{id}', 'show')->name('show');
//         });

//         Route::controller(StockController::class)->name('stocks.')
//             ->group(function () {
//                 Route::get('/stocks', 'index')->name('index');
//                 Route::get('/stocks/show/{id}', 'show')->name('show');
//             });
//         // TBD
//         Route::controller(UsageRecordController::class)
//             ->prefix('usage-records')
//             ->name('usage-records.')
//             ->group(function () {
//                 Route::get('/', 'index')->name('index');
//                 Route::get('/create', 'create')->name('create');
//                 Route::post('/store', 'store')->name('store');
//                 Route::get('/show/{id}', 'show')->name('show');
//             });


//         Route::controller(PersmissionController::class)->name('permissions.')->prefix('permissions')->group(function () {
//             Route::get('/', 'index')->name('index');
//             Route::get('/create', 'create')->name('create');
//             Route::post('/store', 'store')->name('store');
//             Route::get('/edit/{id}', 'edit')->name('edit');
//         });

//         Route::controller(SalesOrderController::class)
//             ->prefix('sales-orders')
//             ->name('sales-orders.')
//             ->group(function () {
//                 Route::get('/', 'index')->name('index');
//                 Route::get('/create', 'create')->name('create');
//                 Route::get('/show/{id}', 'show')->name('show');
//             });


//         Route::controller(ProfileController::class)->name('profile.')
//             ->prefix('profile')
//             ->group(function () {
//                 Route::get('/', 'index')->name('index');
//                 Route::post('/update-details/{id}', 'updateDetails')->name('update-details');
//                 Route::post('/update-password/{id}', 'updatePassword')->name('update-password');
//             });

//         Route::controller(TestController::class)->group(function () {
//             Route::get('/test', 'index')->name('test');
//             Route::post('/uploadImage', 'store')->name('upload-image');
//             Route::post('/destroy', 'destroy')->name('destroy');
//             Route::post('/approveImage/{id}', 'approveImage')->name('approveImage');
//         });





//         // Route::get('/profile', [ProfileController::class, 'edit'])
//         //     ->name('profile.edit');

//         // Route::patch('/profile', [ProfileController::class, 'update'])
//         //     ->name('profile.update');

//         // Route::delete('/profile', [ProfileController::class, 'destroy'])
//         //     ->name('profile.destroy');
//     });

// require __DIR__ . '/auth.php';
