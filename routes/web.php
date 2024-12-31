<?php

use App\Http\Controllers\ApprovedReceivedItem;
use App\Http\Controllers\ApprovedOrderController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryScheduleController;
use App\Http\Controllers\DTSController;
use App\Http\Controllers\ExcelTemplateController;
use App\Http\Controllers\FruitAndVegetableController;
use App\Http\Controllers\IceCreamOrderController;
use App\Http\Controllers\InvetoryCategoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\OrderApprovalController;
use App\Http\Controllers\OrderReceivingController;
use App\Http\Controllers\ProductOrderSummaryController;
use App\Http\Controllers\ProductSalesController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\SalmonOrderController;
use App\Http\Controllers\StoreOrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReceivingApprovalController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockManagementController;
use App\Http\Controllers\StoreBranchController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\UnitOfMeasurementController;
use App\Http\Controllers\UsageRecordController;
use App\Http\Controllers\UserController;
use App\Models\StoreBranch;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')
    ->group(function () {

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
            });

        Route::controller(StoreOrderController::class)
            ->prefix('store-orders')
            ->name('store-orders.')
            ->group(function () {

                Route::get('/', 'index')
                    ->name('index');

                Route::middleware('role:admin|so encoder')->group(function () {
                    Route::get('/show/{id}', 'show')
                        ->name('show');

                    Route::post('/store', 'store')
                        ->name('store');

                    Route::get('/create', 'create')
                        ->name('create');

                    Route::get('/edit/{id}', 'edit')
                        ->name('edit');

                    Route::put('/update/{id}', 'update')
                        ->name('update');

                    Route::post('/orders-list', 'validateHeaderUpload')
                        ->name('orders-list');

                    Route::post('/store-orders', 'getImportedOrders')
                        ->name('imported-file');
                });
            });

        Route::controller(OrderApprovalController::class)->name('orders-approval.')->group(function () {
            Route::get('/orders-approval', 'index')->name('index');
            Route::get('/orders-approval/show/{id}', 'show')->name('show');

            Route::post('/orders-approval/approve', 'approve')->name('approve');

            Route::post('/orders-approval/reject', 'reject')->name('reject');

            Route::post('/orders-approval/add-remarks/{id}', 'addRemarks')->name('add-remarks');
        });

        Route::controller(CategoryController::class)->name('categories.')->group(function () {
            Route::get('/category-list', 'index')->name('index');
            Route::post('/category-list/update/{id}', 'update')->name('update');
        });

        Route::controller(ItemController::class)->name('items.')->group(function () {
            Route::get('/items-list', 'index')->name('index');
            Route::get('/items-list/create', 'create')->name('create');
            Route::get('/items-list/show/{id}', 'show')->name('show');
            Route::post('/items-list/store', 'store')->name('store');

            Route::post('/items-list/import', 'import')->name('import');
        });

        Route::controller(ProductOrderSummaryController::class)->name('product-orders-summary.')->group(function () {
            Route::get('/product-orders-summary', 'index')->name('index');
            Route::get('/product-orders-summary/show/{id}', 'show')->name('show');
        });

        Route::controller(OrderReceivingController::class)->name('orders-receiving.')->group(function () {
            Route::get('/orders-receiving', 'index')->name('index');
            Route::get('/orders-receiving/show/{id}', 'show')->name('show');
            Route::post('/orders-receiving/receive/{id}', 'receive')->name('receive');
            Route::post('/orders-receiving/add-delivery-receipt-number', 'addDeliveryReceiptNumber')->name('add-delivery-receipt-number');

            Route::post('/orders-receiving/delete-receiving-history/{id}', 'deleteReceiveDateHistory')->name('delete-receiving-history');
            Route::post('/orders-receiving/update-receiving-history', 'updateReceiveDateHistory')->name('update-receiving-history');
        });

        Route::controller(UserController::class)->name('users.')->group(function () {
            Route::get('/users', 'index')->name('index');
            Route::get('/users/create', 'create')->name('create');
            Route::post('/users/store', 'store')->name('store');
            Route::get('/users/show/{id}', 'show')->name('show');
            Route::get('/users/edit/{id}', 'edit')->name('edit');
            Route::post('/users/update/{id}', 'update')->name('update');
        });

        Route::controller(ApprovedOrderController::class)->name('approved-orders.')->group(function () {
            Route::get('/approved-orders', 'index')->name('index');
            Route::get('/approved-orders/show/{id}', 'show')->name('show');
        });

        Route::controller(StockController::class)->name('stocks.')
            ->group(function () {
                Route::get('/stocks', 'index')->name('index');
                Route::get('/stocks/show/{id}', 'show')->name('show');
            });

        Route::controller(StoreBranchController::class)->name('store-branches.')->prefix('store-branches')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/store', 'store')->name('store');
            Route::get('/show/{id}', 'show')->name('show');
            Route::get('/edit/{id}', 'edit')->name('edit');
            Route::post('/update/{id}', 'update')->name('update');
        });
        Route::controller(SupplierController::class)->name('suppliers.')->group(function () {
            Route::get('/suppliers', 'index')->name('index');
        });

        Route::controller(InvetoryCategoryController::class)->name('inventory-categories.')->group(function () {
            Route::get('/inventory-categories', 'index')->name('index');
            Route::post('/inventory-categories/update/{id}', 'update')->name('update');
        });

        Route::controller(UnitOfMeasurementController::class)->name('unit-of-measurements.')->group(function () {
            Route::get('/unit-of-measurements', 'index')->name('index');
            Route::post('/unit-of-measurements/update/{id}', 'update')->name('update');
        });

        Route::controller(DTSController::class)->name('dts-orders.')->prefix('dts-orders')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create/{variant}', 'create')->name('create');
            Route::get('/show/{id}', 'show')
                ->name('show');
            Route::post('/store', 'store')->name('store');
            Route::get('/edit/{id}', 'edit')
                ->name('edit');
            Route::put('/update/{id}', 'update')
                ->name('update');
        });

        Route::controller(DeliveryScheduleController::class)->name('delivery-schedules.')->prefix('delivery-schedules')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/edit/{id}', 'edit')->name('edit');
        });

        Route::controller(IceCreamOrderController::class)->name('ice-cream-orders.')->prefix('ice-cream-orders')->group(function () {
            Route::get('/', 'index')->name('index');
        });

        Route::controller(SalmonOrderController::class)->name('salmon-orders.')->prefix('salmon-orders')->group(function () {
            Route::get('/', 'index')->name('index');
        });

        Route::get('/audits', [AuditController::class, 'index']);

        Route::controller(ReceivingApprovalController::class)->prefix('receiving-approvals')->name('receiving-approvals.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/show/{id}', 'show')->name('show');
            Route::post('/approve', 'approveReceivedItem')->name('approve-received-item');
        });

        Route::controller(ProductSalesController::class)->prefix('product-sales')->name('product-sales.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/show/{id}', 'show')->name('show');
        });;

        Route::controller(FruitAndVegetableController::class)
            ->prefix('fruits-and-vegetables')
            ->name('fruits-and-vegetables.')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/show/{id}', 'show')->name('show');
            });

        Route::controller(SalesOrderController::class)
            ->prefix('sales-orders')
            ->name('sales-orders.')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::get('/show/{id}', 'show')->name('show');
            });

        Route::controller(UsageRecordController::class)
            ->prefix('usage-records')
            ->name('usage-records.')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/store', 'store')->name('store');
                Route::get('/show/{id}', 'show')->name('show');
            });

        Route::controller(StockManagementController::class)
            ->prefix('stock-management')
            ->name('stock-management.')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/show/{id}', 'show')->name('show');
                Route::post('/log-usage', 'logUsage')->name('log-usage');
            });




        Route::get('/profile', [ProfileController::class, 'index'])
            ->name('profile.index');

        Route::controller(TestController::class)->group(function () {
            Route::get('/test', 'index')->name('test');
            Route::post('/uploadImage', 'store')->name('upload-image');
        });




        // Route::get('/profile', [ProfileController::class, 'edit'])
        //     ->name('profile.edit');

        // Route::patch('/profile', [ProfileController::class, 'update'])
        //     ->name('profile.update');

        // Route::delete('/profile', [ProfileController::class, 'destroy'])
        //     ->name('profile.destroy');
    });

require __DIR__ . '/auth.php';
