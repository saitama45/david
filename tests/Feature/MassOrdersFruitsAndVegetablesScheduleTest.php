<?php

use App\Http\Controllers\MassOrdersController;
use App\Http\Services\DeliveryScheduleService;
use App\Models\DeliverySchedule;
use App\Models\StoreBranch;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Mass Orders' Fruits and Vegetables is the DROPS template. Its delivery days are filed under
 * DROPS on Delivery Schedules or under FRUITS AND VEGETABLES on DTS Delivery Schedules, and
 * either one puts the store in the template.
 *
 * @return array{0: User, 1: \Illuminate\Support\Collection<string, StoreBranch>}
 */
function fruitsAndVegetablesFixture(): array
{
    $user = User::factory()->create();
    Supplier::create(['supplier_code' => 'DROPS', 'name' => 'DROPSHIPPING', 'is_active' => true, 'is_forapproval_massorders' => false]);
    $user->suppliers()->attach('DROPS');

    $monday = DeliverySchedule::firstOrCreate(['day' => 'MONDAY']);
    $stores = collect([
        'DTS-FV' => 'FRUITS AND VEGETABLES', // set on DTS Delivery Schedules only
        'DSP-FV' => 'DROPS',                 // set on Delivery Schedules only
        'GSI-ONLY' => 'GSI-P',               // another template's day
    ])->map(function ($variant, $code) use ($user, $monday) {
        $store = StoreBranch::create(['branch_code' => $code, 'brand_code' => $code, 'name' => "Store {$code}", 'store_status' => 'active', 'is_active' => true]);
        $store->delivery_schedules()->attach($monday->id, ['variant' => $variant]);
        $user->store_branches()->attach($store->id);

        return $store;
    });

    return [$user, $stores];
}

function fruitsAndVegetablesTemplateStores(string $date): array
{
    $file = app(MassOrdersController::class)->downloadTemplate(
        Request::create('/mass-orders/download-template', 'GET', ['supplier_code' => 'DROPS', 'order_date' => $date])
    )->getFile()->getPathname();

    $sheet = IOFactory::load($file)->getActiveSheet();
    $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1')[0];
    @unlink($file);

    return array_values(array_slice($headers, 7)); // after Ordering Template + the six item columns
}

it('puts a store in the Fruits and Vegetables template on a day from either schedule page', function () {
    [$user] = fruitsAndVegetablesFixture();
    $this->actingAs($user);

    expect(fruitsAndVegetablesTemplateStores(Carbon::parse('next monday')->toDateString()))->toBe(['DSP-FV', 'DTS-FV'])
        ->and(fruitsAndVegetablesTemplateStores(Carbon::parse('next tuesday')->toDateString()))->toBe([]);
});

it('offers the same stores when picking the order date', function () {
    [$user, $stores] = fruitsAndVegetablesFixture();
    $this->actingAs($user);

    $options = app(MassOrdersController::class)->getBranchesForDateAndSupplier(
        Request::create('/mass-orders/get-branches', 'GET', ['supplier_code' => 'DROPS', 'order_date' => Carbon::parse('next monday')->toDateString()])
    )->getData(true);

    expect(array_map('intval', array_keys($options)))->toEqualCanonicalizing([$stores['DTS-FV']->id, $stores['DSP-FV']->id]);
});

it('keeps a template on its own schedule label alone', function () {
    expect(Supplier::scheduleVariants('DROPS'))->toBe(['DROPS', 'FRUITS AND VEGETABLES'])
        ->and(Supplier::scheduleVariants('GSI-P'))->toBe(['GSI-P']);
});

it('saving DTS Delivery Schedules keeps the store on the ordering templates of Delivery Schedules', function () {
    [, $stores] = fruitsAndVegetablesFixture();
    $store = $stores['GSI-ONLY'];
    $friday = DeliverySchedule::firstOrCreate(['day' => 'FRIDAY']);
    $store->delivery_schedules()->attach($friday->id, ['variant' => 'ICE CREAM']);

    app(DeliveryScheduleService::class)->updateDeliveryScedule([
        'ice_cream' => [], 'salmon' => [], 'fruits_and_vegetables' => [$friday->id],
    ], $store->id);

    $variants = $store->delivery_schedules()->get()->map(fn ($day) => $day->pivot->variant.' '.$day->day)->sort()->values()->all();

    expect($variants)->toBe(['FRUITS AND VEGETABLES FRIDAY', 'GSI-P MONDAY']);
});
