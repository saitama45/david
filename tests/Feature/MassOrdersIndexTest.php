<?php

use App\Models\StoreBranch;
use App\Models\Supplier;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function createMassOrdersIndexUser(array $extraPermissions = []): User
{
    foreach (['view mass orders', 'view cost mass orders', 'view suppliers', 'view users'] as $permission) {
        Permission::firstOrCreate(['name' => $permission]);
    }

    $user = User::factory()->create();
    $user->givePermissionTo(array_merge(['view mass orders'], $extraPermissions));

    $branch = StoreBranch::create([
        'branch_code' => 'MASS-BR',
        'name' => 'Mass Orders Branch',
        'store_status' => 'active',
    ]);

    $cpoSupplier = Supplier::create([
        'supplier_code' => 'CPO',
        'name' => 'CPO Supplier',
        'is_active' => true,
    ]);

    $regularSupplier = Supplier::create([
        'supplier_code' => 'REG',
        'name' => 'Regular Supplier',
        'is_active' => true,
    ]);

    $user->store_branches()->attach($branch->id);
    $user->suppliers()->attach([$cpoSupplier->supplier_code, $regularSupplier->supplier_code]);

    return $user;
}

it('shows the CPO ordering template when assigned to the user', function () {
    $user = createMassOrdersIndexUser();
    // createMassOrdersIndexUser already attaches CPO

    $response = $this->actingAs($user)->get(route('mass-orders.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('MassOrders/Index')
            ->where('suppliers', fn ($suppliers) => collect($suppliers)->pluck('value')->contains('CPO'))
            ->etc()
        );
});

it('hides the CPO ordering template when not assigned to the user', function () {
    $user = createMassOrdersIndexUser();
    $user->suppliers()->detach();

    $response = $this->actingAs($user)->get(route('mass-orders.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('MassOrders/Index')
            ->where('suppliers', fn ($suppliers) => ! collect($suppliers)->pluck('value')->contains('CPO'))
            ->etc()
        );
});

it('includes the branch code in the store filter options', function () {
    $user = createMassOrdersIndexUser();

    $response = $this->actingAs($user)->get(route('mass-orders.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('MassOrders/Index')
            ->where('branches', fn ($branches) => collect($branches)
                ->contains(fn ($branch) => $branch['label'] === 'Mass Orders Branch (MASS-BR)'))
            ->etc()
        );
});

it('restricts the store filter options to the branches assigned to a non-admin user', function () {
    $user = createMassOrdersIndexUser();

    StoreBranch::create([
        'branch_code' => 'OTHER-BR',
        'name' => 'Unassigned Branch',
        'store_status' => 'active',
    ]);

    $response = $this->actingAs($user)->get(route('mass-orders.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('MassOrders/Index')
            ->where('branches', fn ($branches) => ! collect($branches)
                ->contains(fn ($branch) => $branch['label'] === 'Unassigned Branch (OTHER-BR)'))
            ->etc()
        );
});

it('lets an admin see every branch and order regardless of individual branch assignment', function () {
    foreach (['view mass orders', 'view cost mass orders', 'view suppliers', 'view users'] as $permission) {
        Permission::firstOrCreate(['name' => $permission]);
    }
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);

    $admin = User::factory()->create();
    $admin->givePermissionTo('view mass orders');
    $admin->assignRole('admin');
    // Deliberately no store_branches assignment for this admin.

    $otherUser = User::factory()->create();
    $branch = StoreBranch::create([
        'branch_code' => 'ADMIN-BR',
        'name' => 'Admin Visible Branch',
        'store_status' => 'active',
    ]);
    $otherUser->store_branches()->attach($branch->id);

    $supplier = Supplier::create([
        'supplier_code' => 'ADMSUP',
        'name' => 'Admin Supplier',
        'is_active' => true,
    ]);

    $order = \App\Models\StoreOrder::create([
        'encoder_id' => $otherUser->id,
        'supplier_id' => $supplier->id,
        'store_branch_id' => $branch->id,
        'order_number' => 'ADMIN-ORDER-' . uniqid(),
        'order_date' => now()->toDateString(),
        'order_status' => 'pending',
        'variant' => 'mass regular',
    ]);

    $response = $this->actingAs($admin)->get(route('mass-orders.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('MassOrders/Index')
            ->where('branches', fn ($branches) => collect($branches)
                ->contains(fn ($b) => $b['label'] === 'Admin Visible Branch (ADMIN-BR)'))
            ->where('massOrders.data', fn ($orders) => collect($orders)
                ->contains(fn ($o) => $o['id'] === $order->id))
            ->etc()
        );
});
