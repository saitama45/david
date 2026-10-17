<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Services\UserService;
use App\Models\Entity;
use App\Models\StoreBranch;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** The fields Users > Edit sends, for $user, with the status given. */
function userEditPayload(User $user, bool $active): array
{
    $branch = StoreBranch::create(['branch_code' => 'ST-'.uniqid(), 'name' => 'Status Store', 'store_status' => 'active', 'is_active' => true]);

    return [
        'first_name' => $user->first_name, 'last_name' => $user->last_name, 'email' => $user->email,
        'phone_number' => '0912 345 6789', 'roles' => [Role::firstOrCreate(['name' => 'Status QA', 'guard_name' => 'web'])->id],
        'assignedBranches' => [$branch->id], 'assignedSuppliers' => [], 'is_active' => $active,
    ];
}

it('saves a user as inactive and active again', function () {
    $user = User::factory()->create(['is_active' => true]);

    app(UserService::class)->updateUser(['is_active' => false], $user);
    expect((bool) $user->fresh()->is_active)->toBeFalse();

    app(UserService::class)->updateUser(['is_active' => true], $user->fresh());
    expect((bool) $user->fresh()->is_active)->toBeTrue();
});

it('refuses to make your own account inactive, but not someone else\'s', function () {
    Permission::firstOrCreate(['name' => 'edit users', 'guard_name' => 'web']);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->givePermissionTo('edit users');
    $other = User::factory()->create(['is_active' => true]);

    $this->actingAs($admin)->post(route('users.update', $admin), userEditPayload($admin, false))
        ->assertSessionHasErrors(['is_active' => 'You cannot make your own account inactive.']);

    $this->actingAs($admin)->post(route('users.update', $other), userEditPayload($other, false))
        ->assertSessionDoesntHaveErrors('is_active');
});

it('does not let an inactive user log in', function () {
    $entity = Entity::create(['name' => 'Status Entity', 'code' => 'STE', 'is_active' => true]);
    $user = User::factory()->create(['is_active' => false, 'password' => bcrypt('secret-pass')]);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'secret-pass', 'entity_id' => $entity->id])
        ->assertSessionHasErrors(['email' => EnsureUserIsActive::MESSAGE]);

    $this->assertGuest();
});

it('signs out a logged-in user once the account is inactive', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->actingAs($user)->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', EnsureUserIsActive::MESSAGE);

    $this->assertGuest();
});
