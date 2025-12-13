<?php

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Inertia\Testing\AssertableInertia as Assert;

test('delivery report pagination preserves query parameters', function () {
    // Create permission
    Permission::create(['name' => 'view delivery report']);

    // Create user and give permission
    $user = User::factory()->create();
    $user->givePermissionTo('view delivery report');

    // Define query parameters
    $queryParams = [
        'date_from' => '2023-01-01',
        'date_to' => '2023-01-31',
        'search' => 'test-item',
    ];

    // Make request
    $response = $this->actingAs($user)
        ->get(route('reports.delivery-report.index', $queryParams));

    // Assert Inertia response
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Reports/DeliveryReport/Index')
        ->has('paginatedData', fn (Assert $paginatedData) => $paginatedData
            ->has('links')
            ->where('links.1.active', true) // Page 1 should be active
            ->where('links.1.label', '1')
            ->where('links.1.url', function ($url) {
                 return str_contains($url, 'date_from=2023-01-01') &&
                        str_contains($url, 'date_to=2023-01-31') &&
                        str_contains($url, 'search=test-item');
            })
            ->etc()
        )
    );
});
