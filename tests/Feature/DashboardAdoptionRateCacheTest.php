<?php

use App\Http\Services\AdoptionRateTrackingService;
use App\Models\StoreBranch;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;

it('keys the dashboard adoption rate cache on the user accessible stores', function () {
    Permission::firstOrCreate(['name' => 'view adoption rate dashboard']);

    $user = User::factory()->create();
    $user->givePermissionTo('view adoption rate dashboard');

    $first = StoreBranch::create(['branch_code' => 'NNONE', 'name' => 'Store One', 'store_status' => 'active', 'is_active' => true]);
    $second = StoreBranch::create(['branch_code' => 'NNTWO', 'name' => 'Store Two', 'store_status' => 'active', 'is_active' => true]);

    $user->store_branches()->attach($first->id);

    $this->actingAs($user)->getJson(route('dashboard.adoption-rate', ['date_from' => '2026-05-01', 'date_to' => '2026-05-31']))
        ->assertOk()
        ->assertJsonPath('meta.store_count', 1);

    $params = [
        'store_ids' => [],
        'date_from' => '2026-05-01',
        'date_to' => '2026-05-31',
        'tab' => AdoptionRateTrackingService::TAB_OVERALL_ADOPTION_RATE,
    ];
    $key = fn (array $storeIds) => 'dashboard_adoption_rate_v4_' . $user->id . '_'
        . (session('active_entity_id') ?? 'none') . '_'
        . md5(json_encode([$params, $storeIds]));

    // Assigning another store changes the key, so the old tally is never served.
    expect(Cache::has($key([(int) $first->id])))->toBeTrue()
        ->and(Cache::has($key([(int) $first->id, (int) $second->id])))->toBeFalse();
});
