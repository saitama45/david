<?php

use App\Http\Controllers\StoreBranchController;
use App\Models\Entity;
use App\Models\StoreBranch;
use App\Models\User;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// The controller is called directly: every test HTTP request disconnects the database on
// terminate(), which rolls back RefreshDatabase's transaction.

afterEach(fn () => Carbon::setTestNow());

it('names who created a branch and who changed it last on its create, edit and view pages', function () {
    // The names come from the audit trail, which is off in the console the tests run in.
    config(['audit.console' => true]);

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $creator = User::factory()->create(['first_name' => 'Adrian', 'last_name' => 'Vivas']);
    $editor = User::factory()->create(['first_name' => 'Dyzel', 'last_name' => 'Sy']);

    $controller = app(StoreBranchController::class);
    $inertia = fn () => Request::create('/branches', 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true']);
    $details = fn ($page) => $page->toResponse($inertia())->getData(true)['props']['recordDetails'];
    $manila = fn (?string $date) => Carbon::parse($date)->timezone('Asia/Manila')->format('Y-m-d H:i');
    $input = ['branch_code' => 'B1', 'name' => 'Branch One', 'store_status' => 'Active', 'is_active' => 1];

    // Before anything is saved, only the person creating the branch is known.
    test()->actingAs($creator);
    expect($details($controller->create()))->toBe(['created_by' => 'Adrian Vivas']);

    Carbon::setTestNow(Carbon::parse('2026-07-03 11:11', 'Asia/Manila'));
    $controller->store(Request::create('/branches/store', 'POST', $input));
    $branch = StoreBranch::where('branch_code', 'B1')->firstOrFail();

    // A branch nobody has edited yet was last changed by the person who created it.
    $saved = $details($controller->edit($branch->id));
    expect($saved['created_by'])->toBe('Adrian Vivas')
        ->and($saved['updated_by'])->toBe('Adrian Vivas')
        ->and($manila($saved['created_at']))->toBe('2026-07-03 11:11')
        ->and($manila($saved['updated_at']))->toBe('2026-07-03 11:11');

    Carbon::setTestNow(Carbon::parse('2026-07-28 06:45', 'Asia/Manila'));
    test()->actingAs($editor);
    $controller->update(Request::create('/branches/update/'.$branch->id, 'POST', ['name' => 'Branch One Renamed'] + $input), $branch->id);

    foreach (['edit', 'show'] as $page) {
        $edited = $details($controller->{$page}($branch->id));
        expect($edited['created_by'])->toBe('Adrian Vivas')
            ->and($edited['updated_by'])->toBe('Dyzel Sy')
            ->and($manila($edited['created_at']))->toBe('2026-07-03 11:11')
            ->and($manila($edited['updated_at']))->toBe('2026-07-28 06:45');
    }

    // A branch that was seeded, with nothing in the audit trail, keeps its dates and has no names.
    $seededId = DB::table('store_branches')->insertGetId([
        'entity_id' => $entity->id, 'branch_code' => 'B2', 'name' => 'Seeded Branch', 'store_status' => 'Active', 'is_active' => 1,
        'created_at' => '2026-01-05 08:00:00', 'updated_at' => '2026-01-05 08:00:00',
    ]);
    $seeded = $details($controller->edit($seededId));
    expect($seeded['created_by'])->toBeNull()
        ->and($seeded['updated_by'])->toBeNull()
        ->and($manila($seeded['created_at']))->toBe('2026-01-05 08:00');
});
