<?php

use App\Http\Controllers\ApprovedOrder;
use App\Http\Controllers\ApprovedOrderController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExcelTemplateController;
use App\Http\Controllers\InvetoryCategoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\OrderApprovalController;
use App\Http\Controllers\OrderReceivingController;
use App\Http\Controllers\StoreOrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StoreBranchController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UnitOfMeasurementController;
use App\Http\Controllers\UserController;
use App\Models\StoreBranch;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')
    ->group(function () {

        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->name('dashboard');


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
            });

        Route::controller(StoreOrderController::class)
            ->prefix('store-orders')
            ->name('store-orders.')
            ->group(function () {

                Route::get('/', 'index')
                    ->name('index');

                Route::get('/show/{id}', 'show')
                    ->name('show');

                Route::middleware('check.persmission:create-so')->group(function () {
                    Route::post('/store', 'store')
                        ->name('store');

                    Route::get('/create', 'create')
                        ->name('create');

                    Route::get('/edit/{id}', 'edit')
                        ->name('edit');

                    Route::put('/update/{id}', 'update')
                        ->name('update');
                });

                Route::post('/orders-list', 'validateHeaderUpload')
                    ->name('orders-list');

                Route::post('/store-orders', 'getImportedOrders')
                    ->name('imported-file');
            });

        Route::controller(OrderApprovalController::class)->name('orders-approval.')->group(function () {
            Route::get('/orders-approval', 'index')->name('index');
            Route::get('/orders-approval/show/{id}', 'show')->name('show');

            Route::post('/orders-approval/approve/{id}', 'approve')->name('approve');

            Route::post('/orders-approval/reject/{id}', 'reject')->name('reject');
        });

        Route::controller(CategoryController::class)->name('categories.')->group(function () {
            Route::get('/category-list', 'index')->name('index');
            Route::post('/category-list/update/{id}', 'update')->name('update');
        });

        Route::controller(ItemController::class)->name('items.')->group(function () {
            Route::get('/items-list', 'index')->name('index');
            Route::get('/items-list/create', 'create')->name('create');
            Route::post('/items-list/store', 'store')->name('store');

            Route::post('/items-list/import', 'import')->name('import');
        });

        Route::controller(OrderReceivingController::class)->name('orders-receiving.')->group(function () {
            Route::get('/orders-receiving', 'index')->name('index');
            Route::get('/orders-receiving/show/{id}', 'show')->name('show');

            Route::post('/orders-receiving/receive/{id}', 'receive')->name('receive');
        });

        Route::controller(UserController::class)->name('users.')->group(function () {
            Route::get('/users', 'index')->name('index');
            Route::get('/users/create', 'create')->name('create');
            Route::post('/users/store', 'store')->name('store');
        });

        Route::controller(ApprovedOrderController::class)->name('approved-orders.')->group(function () {
            Route::get('/approved-orders', 'index')->name('index');
        });

        Route::controller(StoreBranchController::class)->name('store-branches.')->group(function () {
            Route::get('/store-branches', 'index')->name('index');
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

        Route::get('/profile', [ProfileController::class, 'edit'])
            ->name('profile.edit');

        Route::patch('/profile', [ProfileController::class, 'update'])
            ->name('profile.update');

        Route::delete('/profile', [ProfileController::class, 'destroy'])
            ->name('profile.destroy');
    });

require __DIR__ . '/auth.php';

Route::get('/test', function () {
    return phpinfo();
});
