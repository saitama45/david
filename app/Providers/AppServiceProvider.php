<?php

namespace App\Providers;

use App\Models\User;
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
        Gate::define('create-so', function (User $user) {
            return $user->role === 'admin' || $user->role === 'so_encoder';
        });
        Gate::define('edit-so', function (User $user) {
            return $user->role === 'admin' || $user->role === 'so_encoder';
        });
        Gate::define('view-so-per-store', function (User $user) {
            return $user->role === 'admin' || $user->role === 'so_encoder';
        });
        Gate::define('view-so-all-stores', function (User $user) {
            return $user->role === 'admin';
        });
        Gate::define('upload-received-so', function (User $user) {
            return $user->role === 'admin' || $user->role === 'rec_encoder';
        });
        Gate::define('edit-received-so', function (User $user) {
            return $user->role === 'admin' || $user->role === 'rec_encoder';
        });
        Gate::define('approve-received-so', function (User $user) {
            return $user->role === 'admin' || $user->role === 'rec_approver';
        });
        Gate::define('view-approved-received-so-per-store', function (User $user) {
            return $user->role === 'admin' || $user->role === 'rec_encoder';
        });
        Gate::define('view-approved-received-so-all-stores', function (User $user) {
            return $user->role === 'admin';
        });
    }
}
