<?php

use App\Models\StoreBranch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

// Created first but changed last, so the two date columns put the branches in opposite orders.
function createDatedStoreBranches(): void
{
    $old = StoreBranch::create(['branch_code' => 'NNOLD', 'name' => 'Old Branch', 'store_status' => 'active']);
    $new = StoreBranch::create(['branch_code' => 'NNNEW', 'name' => 'New Branch', 'store_status' => 'active']);
    DB::table('store_branches')->where('id', $old->id)->update(['created_at' => '2026-01-05 08:00:00', 'updated_at' => '2026-03-09 08:00:00']);
    DB::table('store_branches')->where('id', $new->id)->update(['created_at' => '2026-02-06 08:00:00', 'updated_at' => '2026-02-06 08:00:00']);
}

function datedStoreBranchOrder($branches): array
{
    return collect($branches)->pluck('branch_code')->intersect(['NNOLD', 'NNNEW'])->values()->all();
}

it('sorts branches by Created At and Updated At in both directions', function (string $sort, string $direction, array $expected) {
    $user = createStoreBranchIndexUser();
    createDatedStoreBranches();

    $this->actingAs($user)->get(route('branches.index', ['sort' => $sort, 'direction' => $direction]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', $sort)
            ->where('filters.direction', $direction)
            ->where('data.data', fn ($branches) => datedStoreBranchOrder($branches) === $expected)
        );
})->with([
    'created at, oldest first' => ['created_at', 'asc', ['NNOLD', 'NNNEW']],
    'created at, newest first' => ['created_at', 'desc', ['NNNEW', 'NNOLD']],
    'updated at, oldest first' => ['updated_at', 'asc', ['NNNEW', 'NNOLD']],
    'updated at, newest first' => ['updated_at', 'desc', ['NNOLD', 'NNNEW']],
]);

// The page reads the sort it shows from these two filters, so they are sent even when the
// request names none, or names a column that does not sort.
it('lists the newest branch first and says so when no valid sort is asked for', function (array $query) {
    $user = createStoreBranchIndexUser();
    createDatedStoreBranches();

    $this->actingAs($user)->get(route('branches.index', $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'created_at')
            ->where('filters.direction', 'desc')
            ->where('data.data', fn ($branches) => datedStoreBranchOrder($branches) === ['NNNEW', 'NNOLD'])
        );
})->with([
    'no sort' => [[]],
    'an unknown column and direction' => [['sort' => 'branch_code', 'direction' => 'sideways']],
]);
