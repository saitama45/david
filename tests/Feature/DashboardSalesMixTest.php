<?php

use App\Models\POSMasterfile;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use App\Models\User;
use Carbon\Carbon;
use Spatie\Permission\Models\Permission;

function createSalesMixUser(bool $withSalesMixPermission = true): User
{
    $user = User::factory()->create();
    $permission = Permission::firstOrCreate(['name' => 'view sales mix']);

    if ($withSalesMixPermission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function createSalesMixBranch(string $code): StoreBranch
{
    return StoreBranch::create([
        'branch_code' => $code,
        'name' => "Store {$code}",
        'store_status' => 'Active',
        'is_active' => true,
    ]);
}

function createSalesMixProduct(string $code, string $subcategory): POSMasterfile
{
    return POSMasterfile::create([
        'POSCode' => $code,
        'POSDescription' => "Product {$code}",
        'Category' => 'Food',
        'SubCategory' => $subcategory,
        'SRP' => 0,
        'DeliveryPrice' => 0,
        'TableVibePrice' => 0,
        'is_active' => true,
    ]);
}

function createSalesMixTransaction(StoreBranch $branch, string $date, POSMasterfile $product, float $netTotal, float $quantity = 1): void
{
    $transaction = StoreTransaction::create([
        'store_branch_id' => $branch->id,
        'order_date' => $date,
        'posted' => 'Y',
        'tim_number' => "TIM-{$branch->id}-{$date}",
        'receipt_number' => "R-{$branch->id}-{$product->id}-{$date}",
    ]);

    $transaction->store_transaction_items()->create([
        'product_id' => $product->id,
        'base_quantity' => $quantity,
        'quantity' => $quantity,
        'price' => $netTotal,
        'discount' => 0,
        'line_total' => $netTotal,
        'net_total' => $netTotal,
    ]);
}

test('dashboard sales mix ranks subcategories by revenue and includes zero revenue masterlist rows', function () {
    Carbon::setTestNow('2026-05-24');

    $user = createSalesMixUser();
    $branch = createSalesMixBranch('S001');
    $user->store_branches()->attach($branch->id);

    $mains = createSalesMixProduct('P001', 'Mains');
    $pasta = createSalesMixProduct('P002', 'Pasta');
    createSalesMixProduct('P003', 'Nono\'s To Go');

    createSalesMixTransaction($branch, '2026-05-10', $mains, 300);
    createSalesMixTransaction($branch, '2026-05-11', $pasta, 100);

    $response = $this->actingAs($user)->getJson(route('dashboard.sales-mix.subcategories', [
        'branch' => ['all'],
        'date_from' => '2026-05-01',
        'date_to' => '2026-05-24',
    ]));

    $response->assertOk()
        ->assertJsonPath('meta.total_revenue', 400)
        ->assertJsonPath('data.0.rank', 1)
        ->assertJsonPath('data.0.sub_category', 'Mains')
        ->assertJsonPath('data.0.revenue', 300)
        ->assertJsonPath('data.0.revenue_percent', 75)
        ->assertJsonPath('data.1.sub_category', 'Pasta')
        ->assertJsonPath('data.1.revenue_percent', 25);

    expect(collect($response->json('data'))->firstWhere('sub_category', 'Nono\'s To Go'))
        ->toMatchArray([
            'revenue' => 0,
            'revenue_percent' => 0,
        ]);

    Carbon::setTestNow();
});

test('dashboard sales mix filters by assigned stores and date range', function () {
    $user = createSalesMixUser();
    $assignedBranch = createSalesMixBranch('S001');
    $unassignedBranch = createSalesMixBranch('S002');
    $user->store_branches()->attach($assignedBranch->id);

    $mains = createSalesMixProduct('P001', 'Mains');
    $pasta = createSalesMixProduct('P002', 'Pasta');

    createSalesMixTransaction($assignedBranch, '2026-05-10', $mains, 300);
    createSalesMixTransaction($assignedBranch, '2026-04-10', $mains, 999);
    createSalesMixTransaction($unassignedBranch, '2026-05-10', $pasta, 700);

    $response = $this->actingAs($user)->getJson(route('dashboard.sales-mix.subcategories', [
        'branch' => ['all'],
        'date_from' => '2026-05-01',
        'date_to' => '2026-05-31',
    ]));

    $response->assertOk()
        ->assertJsonPath('meta.total_revenue', 300)
        ->assertJsonPath('meta.store_count', 1)
        ->assertJsonPath('data.0.sub_category', 'Mains');
});

test('dashboard sales mix validates date range', function () {
    $user = createSalesMixUser();

    $response = $this->actingAs($user)->getJson(route('dashboard.sales-mix.subcategories', [
        'date_from' => '2026-05-24',
        'date_to' => '2026-05-01',
    ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['date_to']);
});

test('dashboard sales mix ranks products by revenue and derives main categories', function () {
    $user = createSalesMixUser();
    $branch = createSalesMixBranch('S001');
    $user->store_branches()->attach($branch->id);

    $kitchen = createSalesMixProduct('NON01010004', 'All Day Breakfast');
    $bakery = createSalesMixProduct('NON02030011', 'Whole Cakes');
    $beverage = createSalesMixProduct('NON03070004', 'Set Meals');
    $uncategorized = createSalesMixProduct('ABC0001', '');

    createSalesMixTransaction($branch, '2026-05-10', $kitchen, 300);
    createSalesMixTransaction($branch, '2026-05-10', $bakery, 200);
    createSalesMixTransaction($branch, '2026-05-10', $beverage, 100);

    $response = $this->actingAs($user)->getJson(route('dashboard.sales-mix.products.revenue', [
        'branch' => ['all'],
        'date_from' => '2026-05-01',
        'date_to' => '2026-05-31',
    ]));

    $response->assertOk()
        ->assertJsonPath('meta.total_revenue', 600)
        ->assertJsonPath('overall.0.rank', 1)
        ->assertJsonPath('overall.0.pos_code', 'NON01010004')
        ->assertJsonPath('overall.0.item_name', 'Product NON01010004')
        ->assertJsonPath('overall.0.revenue', 300)
        ->assertJsonPath('overall.0.category', 'Kitchen')
        ->assertJsonPath('overall.0.sub_category', 'All Day Breakfast');

    $payload = $response->json();

    expect($payload['by_category']['Kitchen'][0]['pos_code'])->toBe('NON01010004')
        ->and($payload['by_category']['Beverages & Others'][0]['pos_code'])->toBe('NON03070004')
        ->and($payload['by_category']['Bakery'][0]['pos_code'])->toBe('NON02030011');

    expect(collect($payload['overall'])->firstWhere('pos_code', $uncategorized->POSCode))
        ->toMatchArray([
            'revenue' => 0,
            'category' => 'Uncategorized',
            'sub_category' => 'Uncategorized',
        ]);
});

test('dashboard sales mix product revenue uses shared ranks with gaps for ties', function () {
    $user = createSalesMixUser();
    $branch = createSalesMixBranch('S001');
    $user->store_branches()->attach($branch->id);

    $first = createSalesMixProduct('NON01010001', 'All Day Breakfast');
    $second = createSalesMixProduct('NON01010002', 'All Day Breakfast');
    $third = createSalesMixProduct('NON01010003', 'All Day Breakfast');

    createSalesMixTransaction($branch, '2026-05-10', $first, 100);
    createSalesMixTransaction($branch, '2026-05-10', $second, 100);
    createSalesMixTransaction($branch, '2026-05-10', $third, 50);

    $response = $this->actingAs($user)->getJson(route('dashboard.sales-mix.products.revenue', [
        'branch' => ['all'],
        'date_from' => '2026-05-01',
        'date_to' => '2026-05-31',
    ]));

    $response->assertOk();

    expect(collect($response->json('overall'))->take(3)->pluck('rank')->all())
        ->toBe([1, 1, 3]);
});

test('dashboard sales mix ranks products by quantity and derives main categories', function () {
    $user = createSalesMixUser();
    $branch = createSalesMixBranch('S001');
    $user->store_branches()->attach($branch->id);

    $kitchen = createSalesMixProduct('NON01010004', 'All Day Breakfast');
    $bakery = createSalesMixProduct('NON02070010', 'Sweet Treats');
    $beverage = createSalesMixProduct('NON03020008', 'Specialty Coffee');
    $uncategorized = createSalesMixProduct('ABC0001', '');

    createSalesMixTransaction($branch, '2026-05-10', $kitchen, 300, 10);
    createSalesMixTransaction($branch, '2026-05-10', $bakery, 200, 8);
    createSalesMixTransaction($branch, '2026-05-10', $beverage, 100, 12);

    $response = $this->actingAs($user)->getJson(route('dashboard.sales-mix.products.quantity', [
        'branch' => ['all'],
        'date_from' => '2026-05-01',
        'date_to' => '2026-05-31',
    ]));

    $response->assertOk()
        ->assertJsonPath('meta.total_quantity', 30)
        ->assertJsonPath('overall.0.rank', 1)
        ->assertJsonPath('overall.0.pos_code', 'NON03020008')
        ->assertJsonPath('overall.0.item_name', 'Product NON03020008')
        ->assertJsonPath('overall.0.quantity', 12)
        ->assertJsonPath('overall.0.category', 'Beverages & Others')
        ->assertJsonPath('overall.0.sub_category', 'Specialty Coffee');

    $payload = $response->json();

    expect($payload['by_category']['Kitchen'][0]['pos_code'])->toBe('NON01010004')
        ->and($payload['by_category']['Beverages & Others'][0]['pos_code'])->toBe('NON03020008')
        ->and($payload['by_category']['Bakery'][0]['pos_code'])->toBe('NON02070010');

    expect(collect($payload['overall'])->firstWhere('pos_code', $uncategorized->POSCode))
        ->toMatchArray([
            'quantity' => 0,
            'category' => 'Uncategorized',
            'sub_category' => 'Uncategorized',
        ]);
});

test('dashboard sales mix product quantity uses shared ranks with gaps for ties', function () {
    $user = createSalesMixUser();
    $branch = createSalesMixBranch('S001');
    $user->store_branches()->attach($branch->id);

    $first = createSalesMixProduct('NON01010001', 'All Day Breakfast');
    $second = createSalesMixProduct('NON01010002', 'All Day Breakfast');
    $third = createSalesMixProduct('NON01010003', 'All Day Breakfast');

    createSalesMixTransaction($branch, '2026-05-10', $first, 100, 5);
    createSalesMixTransaction($branch, '2026-05-10', $second, 100, 5);
    createSalesMixTransaction($branch, '2026-05-10', $third, 50, 2);

    $response = $this->actingAs($user)->getJson(route('dashboard.sales-mix.products.quantity', [
        'branch' => ['all'],
        'date_from' => '2026-05-01',
        'date_to' => '2026-05-31',
    ]));

    $response->assertOk();

    expect(collect($response->json('overall'))->take(3)->pluck('rank')->all())
        ->toBe([1, 1, 3]);
});

test('dashboard sales mix endpoints require view sales mix permission', function () {
    $user = createSalesMixUser(false);

    $this->actingAs($user)
        ->getJson(route('dashboard.sales-mix.subcategories'))
        ->assertForbidden();

    $this->actingAs($user)
        ->getJson(route('dashboard.sales-mix.products.revenue'))
        ->assertForbidden();

    $this->actingAs($user)
        ->getJson(route('dashboard.sales-mix.products.quantity'))
        ->assertForbidden();
});
