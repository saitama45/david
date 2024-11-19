<?php

use App\Http\Controllers\ApprovedOrder;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExcelTemplateController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\OrderApprovalController;
use App\Http\Controllers\OrderReceivingController;
use App\Http\Controllers\StoreOrderController;
use App\Http\Controllers\ProfileController;
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

                Route::post('/store', 'store')
                    ->name('store');

                Route::post('/orders-list', 'validateHeaderUpload')
                    ->name('orders-list');

                Route::get('/create', 'create')
                    ->name('store-orders-create');

                Route::post('/store-orders', 'getImportedOrders')
                    ->name('imported-file');
            });

        Route::controller(OrderApprovalController::class)->name('orders-approval.')->group(function () {
            Route::get('/orders-approval', 'index')->name('index');
            Route::get('/orders-approval/show/{id}', 'show')->name('show');
        });

        Route::controller(CategoryController::class)->name('categories.')->group(function () {
            Route::get('/category-list', 'index')->name('index');
            Route::post('/category-list/update/{classfication}', 'update')->name('update');
        });

        Route::controller(ItemController::class)->name('items.')->group(function () {
            Route::get('/items-list', 'index')->name('index');
        });

        Route::controller(OrderReceivingController::class)->name('orders-receiving.')->group(function () {
            Route::get('/orders-receiving', 'index')->name('index');
        });

        Route::controller(ApprovedOrder::class)->name('approved-orders.')->group(function () {
            Route::get('/approved-orders', 'index')->name('index');
        });



        Route::get('/profile', [ProfileController::class, 'edit'])
            ->name('profile.edit');

        Route::patch('/profile', [ProfileController::class, 'update'])
            ->name('profile.update');

        Route::delete('/profile', [ProfileController::class, 'destroy'])
            ->name('profile.destroy');
    });

require __DIR__ . '/auth.php';
