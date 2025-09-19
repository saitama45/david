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
                'import_summary' => fn() => $request->session()->get('import_summary'),
                'success' => fn() => $request->session()->get('success'),
                'error' => fn() => $request->session()->get('error'),
                'skippedItems' => fn () => $request->session()->get('skippedItems'),
                'warning' => fn () => $request->session()->get('warning'), // Explicitly shared
                'skipped_import_rows' => fn () => $request->session()->get('skipped_import_rows'), // Explicitly shared
                'created_count' => fn () => $request->session()->get('created_count'),
                'skipped_stores' => fn () => $request->session()->get('skipped_stores'),
            ],
            'previous' => fn() => URL::previous(),
        ];
    }
}
