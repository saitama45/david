<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? $request->user()->load('roles', 'permissions') : null,
                'roles' => $request->user() ? $request->user()->getRoleNames() : [],
                'permissions' => $request->user() ? $request->user()->getAllPermissions()->pluck('name') : [],
                'is_admin' => $request->user() ? $request->user()->hasRole('admin') : false,
            ],
            'flash' => [
                'message' => fn() => $request->session()->get('message'),
                // ADD THIS LINE FOR IMPORT SUMMARY
                'import_summary' => fn() => $request->session()->get('import_summary'),
                // You can add 'success' and 'error' here too if you use them elsewhere with flash()
                'success' => fn() => $request->session()->get('success'),
                'error' => fn() => $request->session()->get('error'),
                'skippedItems' => fn () => $request->session()->get('skippedItems'),
            ],
            'previous' => fn() => URL::previous(),
        ];
    }
}