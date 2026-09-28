<?php

use App\Models\MonthEndSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

// The reopen control lives in the Store Progress modal, which the page may only
// open for a count whose date has passed - even when nobody has submitted yet.
it('flags which counts are past so a count with no submissions can still be reopened', function () {
    Permission::firstOrCreate(['name' => 'view month end schedules']);
    Permission::firstOrCreate(['name' => 'reopen month end count']);

    $user = User::factory()->create();
    $user->givePermissionTo(['view month end schedules', 'reopen month end count']);

    $today = Carbon::today('Asia/Manila');

    $past = MonthEndSchedule::create([
        'year' => $today->year, 'month' => 1,
        'calculated_date' => $today->copy()->subDay()->toDateString(),
        'created_by' => $user->id,
    ]);
    $countingToday = MonthEndSchedule::create([
        'year' => $today->year, 'month' => 2,
        'calculated_date' => $today->toDateString(),
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)->get(route('month-end-schedules.index', ['year' => $today->year]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('MonthEndSchedule/Index')
            ->where('can.reopen_month_end_count', true)
            ->where('schedules.data', function ($schedules) use ($past, $countingToday) {
                $byId = collect($schedules)->keyBy('id');

                return $byId[$past->id]['count_date_passed'] === true
                    && $byId[$countingToday->id]['count_date_passed'] === false
                    && collect($byId[$past->id]['progress'])->sum() === 0;
            })
        );
});
