<?php

use App\Models\StoreBranch;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function createStoreBranchIndexUser(): User
{
    Permission::firstOrCreate(['name' => 'view branches']);

    $user = User::factory()->create();
    $user->givePermissionTo('view branches');

    return $user;
}

it('finds a branch by its branch code', function () {
    $user = createStoreBranchIndexUser();

    StoreBranch::create([
        'branch_code' => 'NNSFW',
        'name' => 'SM Fairview',
        'store_status' => 'active',
    ]);

    StoreBranch::create([
        'branch_code' => 'NNVER',
        'name' => 'Vermosa',
        'store_status' => 'active',
    ]);

    $response = $this->actingAs($user)->get(route('branches.index', ['search' => 'NNSFW']));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('StoreBranch/Index')
            ->where('data.data', fn ($branches) => collect($branches)->pluck('branch_code')->contains('NNSFW')
                && ! collect($branches)->pluck('branch_code')->contains('NNVER'))
        );
});

it('still finds a branch by name', function () {
    $user = createStoreBranchIndexUser();

    StoreBranch::create([
        'branch_code' => 'NNSFW',
        'name' => 'SM Fairview',
        'store_status' => 'active',
    ]);

    $response = $this->actingAs($user)->get(route('branches.index', ['search' => 'Fairview']));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('StoreBranch/Index')
            ->where('data.data', fn ($branches) => collect($branches)->pluck('branch_code')->contains('NNSFW'))
        );
});

it('filters branches by the active status card', function () {
    $user = createStoreBranchIndexUser();

    StoreBranch::create(['branch_code' => 'NNACT', 'name' => 'Active Branch', 'store_status' => 'active', 'is_active' => true]);
    StoreBranch::create(['branch_code' => 'NNINA', 'name' => 'Inactive Branch', 'store_status' => 'active', 'is_active' => false]);

    $this->actingAs($user)->get(route('branches.index', ['status' => 'active']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'active')
            ->where('data.data', fn ($branches) => collect($branches)->pluck('branch_code')->contains('NNACT')
                && ! collect($branches)->pluck('branch_code')->contains('NNINA'))
        );
});

it('filters branches by the inactive status card', function () {
    $user = createStoreBranchIndexUser();

    StoreBranch::create(['branch_code' => 'NNACT', 'name' => 'Active Branch', 'store_status' => 'active', 'is_active' => true]);
    StoreBranch::create(['branch_code' => 'NNINA', 'name' => 'Inactive Branch', 'store_status' => 'active', 'is_active' => false]);

    $this->actingAs($user)->get(route('branches.index', ['status' => 'inactive']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('data.data', fn ($branches) => collect($branches)->pluck('branch_code')->contains('NNINA')
                && ! collect($branches)->pluck('branch_code')->contains('NNACT'))
        );
});
