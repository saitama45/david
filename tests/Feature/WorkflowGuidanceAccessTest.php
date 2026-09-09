<?php

namespace Tests\Feature;

use App\Http\Services\WorkflowGuidanceService;
use App\Models\User;
use App\Support\EntityContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WorkflowGuidanceAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // This fixture never migrates or connects to the application's SQL Server database.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key');
            $t->string('value');
            $t->string('type');
            $t->timestamps();
        });
        foreach (['roles', 'permissions'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('guard_name');
                $t->timestamps();
            });
        }
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('first_name');
            $t->string('last_name');
            $t->boolean('is_active');
            $t->softDeletes();
        });
        Schema::create('entities', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('is_active');
        });
        Schema::create('store_branches', function (Blueprint $t) {
            $t->id();
            $t->integer('entity_id');
            $t->string('name');
            $t->boolean('is_active');
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->integer('entity_id');
            $t->string('supplier_code');
        });
        foreach (['model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id'] as $table => $column) {
            Schema::create($table, function (Blueprint $t) use ($column) {
                $t->integer($column);
                $t->string('model_type');
                $t->integer('model_id');
            });
        }
        foreach (['role_has_permissions' => ['role_id', 'permission_id'], 'entity_role' => ['entity_id', 'role_id'], 'user_assigned_store_branches' => ['user_id', 'store_branch_id']] as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $column) {
                    $t->integer($column);
                }
            });
        }
        Schema::create('user_suppliers', function (Blueprint $t) {
            $t->integer('user_id');
            $t->string('supplier_code');
            $t->timestamps();
        });
        DB::table('entities')->insert([['id' => 1, 'name' => 'One', 'is_active' => true], ['id' => 2, 'name' => 'Two', 'is_active' => true]]);
        DB::table('store_branches')->insert([['id' => 10, 'entity_id' => 1, 'name' => 'A', 'is_active' => true], ['id' => 11, 'entity_id' => 1, 'name' => 'B', 'is_active' => true]]);
        DB::table('roles')->insert([['id' => 1, 'name' => 'OPS-Store Manager', 'guard_name' => 'web'], ['id' => 2, 'name' => 'Other Entity Approver', 'guard_name' => 'web']]);
        DB::table('entity_role')->insert([['entity_id' => 1, 'role_id' => 1], ['entity_id' => 2, 'role_id' => 2]]);
        DB::table('permissions')->insert([['id' => 1, 'name' => 'view wastage approval level 1', 'guard_name' => 'web'], ['id' => 2, 'name' => 'approve wastage level 1', 'guard_name' => 'web']]);
        DB::table('role_has_permissions')->insert([['role_id' => 1, 'permission_id' => 1], ['role_id' => 1, 'permission_id' => 2], ['role_id' => 2, 'permission_id' => 1], ['role_id' => 2, 'permission_id' => 2]]);
        foreach ([1, 2, 3, 4] as $id) {
            DB::table('users')->insert(['id' => $id, 'first_name' => 'User', 'last_name' => (string) $id, 'is_active' => $id !== 4]);
            DB::table('model_has_roles')->insert(['model_id' => $id, 'model_type' => User::class, 'role_id' => $id === 3 ? 2 : 1]);
            DB::table('user_assigned_store_branches')->insert(['user_id' => $id, 'store_branch_id' => $id === 2 ? 11 : 10]);
        }
        app(EntityContext::class)->set(1);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_catalog_excludes_other_branches_entities_and_inactive_people(): void
    {
        $definition = (new WorkflowGuidanceService)->catalog(User::findOrFail(1))['wastage_1'];
        $this->assertSame([1], array_column($definition['people'], 'id'));
        $this->assertSame(['OPS-Store Manager'], $definition['people'][0]['roles']);
        $this->assertTrue($definition['can_act']);
    }

    public function test_guidance_follows_the_saved_wastage_approval_setup(): void
    {
        $settings = app(\App\Http\Services\WastageApprovalSettingsService::class);
        $viewer = User::findOrFail(1);
        $settings->setRequiredLevels(1);
        $catalog = (new WorkflowGuidanceService)->catalog($viewer);
        $this->assertSame('Review wastage Level 1 (final approval)', $catalog['wastage_1']['label']);
        $this->assertStringContainsString('Level 1 final approval', $catalog['wastage']['rule']);
        $this->assertStringContainsString('before the approval setup changed', $catalog['wastage_2']['rule']);

        $settings->setRequiredLevels(2);
        $catalog = (new WorkflowGuidanceService)->catalog($viewer);
        $this->assertSame('Review wastage Level 1', $catalog['wastage_1']['label']);
        $this->assertStringContainsString('goes to Level 2', $catalog['wastage_1']['rule']);
        $this->assertStringContainsString('Level 2 final approval', $catalog['wastage_2']['rule']);
    }

    public function test_responsibility_displays_only_non_admin_roles_without_user_names(): void
    {
        foreach ([3 => 'admin', 4 => 'CT Admin'] as $id => $name) {
            DB::table('roles')->insert(['id' => $id, 'name' => $name, 'guard_name' => 'web']);
            DB::table('entity_role')->insert(['entity_id' => 1, 'role_id' => $id]);
            foreach ([1, 2] as $permissionId) {
                DB::table('role_has_permissions')->insert(['role_id' => $id, 'permission_id' => $permissionId]);
            }
            DB::table('model_has_roles')->insert(['model_id' => 1, 'model_type' => User::class, 'role_id' => $id]);
        }
        DB::table('users')->insert(['id' => 5, 'first_name' => 'Admin', 'last_name' => 'Only', 'is_active' => true]);
        DB::table('model_has_roles')->insert(['model_id' => 5, 'model_type' => User::class, 'role_id' => 3]);
        DB::table('user_assigned_store_branches')->insert(['user_id' => 5, 'store_branch_id' => 10]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $service = new WorkflowGuidanceService;
        $viewer = User::findOrFail(1);
        $people = collect($service->catalog($viewer)['wastage_1']['people']);
        $this->assertSame(['OPS-Store Manager'], $people->firstWhere('id', 1)['roles']);
        $this->assertSame([], $people->firstWhere('id', 5)['roles']);
        foreach ($people as $person) {
            $this->assertArrayNotHasKey('name', $person);
        }
        $task = $service->task($viewer, 'wastage_1', ['branch_id' => 10]);
        $this->assertSame(['OPS-Store Manager'], $task['roles']);
        $this->assertTrue($task['can_act']);
    }

    public function test_edit_permission_without_module_access_is_not_actionable(): void
    {
        DB::table('role_has_permissions')->where('permission_id', 1)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $definition = (new WorkflowGuidanceService)->catalog(User::findOrFail(1))['wastage_1'];
        $this->assertFalse($definition['can_act']);
        $this->assertNull($definition['url']);
        $this->assertSame([], $definition['people']);
    }

    public function test_missing_counts_include_an_older_reopened_branch_and_exclude_uploaded_counts(): void
    {
        Schema::create('month_end_schedules', function (Blueprint $t) {
            $t->id();
            $t->integer('entity_id');
            $t->date('calculated_date');
        });
        Schema::create('month_end_count_items', function (Blueprint $t) {
            $t->id();
            $t->integer('entity_id');
            $t->integer('branch_id');
            $t->integer('month_end_schedule_id');
            $t->string('status');
        });
        Schema::create('month_end_count_reopens', function (Blueprint $t) {
            $t->id();
            $t->integer('entity_id');
            $t->integer('branch_id');
            $t->integer('month_end_schedule_id');
            $t->dateTime('reopened_until');
        });
        DB::table('month_end_schedules')->insert([
            ['id' => 1, 'entity_id' => 1, 'calculated_date' => '2026-07-31'],
            ['id' => 2, 'entity_id' => 1, 'calculated_date' => '2026-08-31'],
        ]);
        DB::table('month_end_count_reopens')->insert(['entity_id' => 1, 'branch_id' => 10, 'month_end_schedule_id' => 1, 'reopened_until' => '2026-09-10 23:59:59']);
        $settings = $this->getMockBuilder(\App\Http\Services\MonthEndCountSettingsService::class)->onlyMethods(['current'])->getMock();
        $settings->method('current')->willReturn(\App\Models\MonthEndCountSetting::defaults());
        app()->instance(\App\Http\Services\MonthEndCountSettingsService::class, $settings);
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-09-09 12:00:00', 'Asia/Manila'));
        try {
            $service = new \App\Http\Services\MyActionsService(new WorkflowGuidanceService, new \App\Http\Services\AdoptionRateTrackingService);
            $method = new \ReflectionMethod($service, 'missingCounts');
            $user = User::findOrFail(1);
            $branches = $user->store_branches()->get()->keyBy('id');
            $tasks = collect();
            $method->invoke($service, $user, $branches, $tasks);
            $this->assertCount(2, $tasks);
            $this->assertSame('2026-09-10 23:59:59', $tasks->firstWhere('id', 'mec-missing:1:10')['deadline']);
            DB::table('month_end_count_items')->insert(['entity_id' => 1, 'branch_id' => 10, 'month_end_schedule_id' => 2, 'status' => 'uploaded']);
            $tasks = collect();
            $method->invoke($service, $user, $branches, $tasks);
            $this->assertCount(1, $tasks);
            $this->assertSame('mec-missing:1:10', $tasks->first()['id']);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }
}
