<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
        // $permissions = [
        //     'admin-access' => ['admin'],
        //     'create-so' => ['admin', 'so_encoder'],
        //     'edit-so' => ['admin', 'so_encoder'],
        //     'view-so-per-store' => ['admin', 'so_encoder'],
        //     'view-so-all-stores' => ['admin'],
        //     'upload-received-so' => ['admin', 'rec_encoder'],
        //     'edit-received-so' => ['admin', 'rec_encoder'],
        //     'approve-received-so' => ['admin', 'rec_approver'],
        //     'view-approved-received-so-per-store' => ['admin', 'rec_encoder'],
        //     'view-approved-received-so-all-stores' => ['admin']
        // ];

        // foreach ($permissions as $permission => $roles) {
        //     Gate::define($permission, fn(User $user) => in_array($user->role, $roles));
        // }

        // register_shutdown_function(function () {
        //     DB::disconnect();
        // });
    }
}
