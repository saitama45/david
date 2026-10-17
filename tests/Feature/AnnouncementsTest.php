<?php

use App\Http\Controllers\SidebarManagementController;
use App\Http\Middleware\CheckSidebarMenuActive;
use App\Models\Announcement;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('finds the module of a page and of its other pages', function () {
    expect(CheckSidebarMenuActive::menuKeyFor('mass-orders.index'))->toBe('ordering.mass.mass-orders')
        ->and(CheckSidebarMenuActive::menuKeyFor('orders-receiving.show'))->toBe('receiving.inbound-orders')
        ->and(CheckSidebarMenuActive::menuKeyFor('mass-orders-approval.show'))->toBe('ordering.mass.mass-orders-approval')
        ->and(CheckSidebarMenuActive::menuKeyFor('cpo-orders.show'))->toBe('ordering.mass.cpo-orders')
        ->and(CheckSidebarMenuActive::menuKeyFor('reports.inventory-movement.details'))->toBe('reports.inventory-movement')
        ->and(CheckSidebarMenuActive::menuKeyFor('change-log.index'))->toBeNull()
        ->and(CheckSidebarMenuActive::menuKeyFor(null))->toBeNull();
});

it('shows an active announcement inside its dates, for every entity or its own', function () {
    $nonos = Entity::create(['name' => 'Nonos QA', 'code' => 'NQA', 'is_active' => true]);
    $cbtl = Entity::create(['name' => 'CBTL QA', 'code' => 'CQA', 'is_active' => true]);
    $base = ['menu_key' => 'ordering.mass.mass-orders', 'message' => 'm', 'tone' => 'info', 'is_active' => true];

    Announcement::create(['title' => 'all'] + $base);
    Announcement::create(['title' => 'nonos only', 'entity_id' => $nonos->id] + $base);
    Announcement::create(['title' => 'off', 'is_active' => false] + $base);
    Announcement::create(['title' => 'later', 'starts_on' => '2026-11-01'] + $base);
    Announcement::create(['title' => 'over', 'ends_on' => '2026-10-16'] + $base);
    Announcement::create(['title' => 'last day', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-17'] + $base);
    Announcement::create(['menu_key' => 'receiving.inbound-orders', 'title' => 'other module'] + $base);

    $titles = fn ($entityId) => Announcement::showingOn('ordering.mass.mass-orders', $entityId, '2026-10-17')->pluck('title')->sort()->values()->all();

    expect($titles($nonos->id))->toBe(['all', 'last day', 'nonos only'])
        ->and($titles($cbtl->id))->toBe(['all', 'last day'])
        ->and($titles(null))->toBe(['all', 'last day']);
});

it('saves an announcement for a sidebar page only, with its end date not before its start', function () {
    $this->actingAs(User::factory()->create());
    $controller = app(SidebarManagementController::class);
    $data = ['menu_key' => 'ordering.mass.mass-orders', 'title' => 'CPO moved', 'message' => 'Use CPO Orders.', 'tone' => 'info', 'is_active' => true];

    $controller->storeAnnouncement(Request::create('/sidebar-management/announcements', 'POST', $data));
    expect(Announcement::where('title', 'CPO moved')->exists())->toBeTrue();

    expect(fn () => $controller->storeAnnouncement(Request::create('/', 'POST', ['menu_key' => 'not-a-page'] + $data)))
        ->toThrow(ValidationException::class)
        ->and(fn () => $controller->storeAnnouncement(Request::create('/', 'POST', ['starts_on' => '2026-10-20', 'ends_on' => '2026-10-19'] + $data)))
        ->toThrow(ValidationException::class);
});

it('ticks the CPO Orders permissions on the roles of users who order CPO, as their Mass Orders access allowed', function () {
    foreach (['view mass orders', 'create mass orders'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    $orders = Role::create(['name' => 'QA Orders', 'guard_name' => 'web'])->givePermissionTo(['view mass orders', 'create mass orders']);
    $viewer = Role::create(['name' => 'QA Viewer', 'guard_name' => 'web'])->givePermissionTo('view mass orders');
    $noCpo = Role::create(['name' => 'QA No CPO', 'guard_name' => 'web'])->givePermissionTo(['view mass orders', 'create mass orders']);

    \App\Models\Supplier::create(['supplier_code' => 'CPO', 'name' => 'CPO', 'is_active' => true]);
    foreach ([$orders, $viewer] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->suppliers()->attach('CPO');
    }
    User::factory()->create()->assignRole($noCpo);

    (require base_path('database/migrations/2026_10_17_130001_grant_cpo_orders_permissions_to_cpo_roles.php'))->up();

    $granted = fn (Role $role) => DB::table('role_has_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
        ->where('rp.role_id', $role->id)->where('p.name', 'like', '%cpo orders')->orderBy('p.name')->pluck('p.name')->all();

    expect($granted($orders))->toBe(['create cpo orders', 'view cpo orders'])
        ->and($granted($viewer))->toBe(['view cpo orders'])
        ->and($granted($noCpo))->toBe([]);
});
