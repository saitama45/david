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
