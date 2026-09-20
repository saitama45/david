<?php

namespace Tests\Unit;

use App\Services\MonthEndStockAdjustment;
use App\Support\EntityContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Run without project phpunit.xml: every connection is replaced before providers boot. */
class MonthEndStockAdjustmentTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app) {
            $app['config']->set('database', ['default' => 'sqlite', 'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            ]]);
            $app['config']->set('cache.default', 'array');
            $app['config']->set('session.driver', 'array');
            $app['config']->set('auditing.enabled', false);
        });
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        app(EntityContext::class)->set(1);
        Storage::fake();
        foreach ([
            'store_branches' => ['name'],
            'sap_masterfiles' => ['ItemCode','BaseUOM','AltUOM'],
            'product_inventory_stocks' => ['product_inventory_id','store_branch_id','quantity','recently_added','used'],
            'product_inventory_stock_managers' => ['product_inventory_id','store_branch_id','quantity','action','transaction_date','unit_cost','total_cost','is_stock_adjustment','is_stock_adjustment_approved','remarks'],
            'month_end_schedules' => ['calculated_date','month','year','status'],
            'month_end_count_items' => ['month_end_schedule_id','branch_id','sap_masterfile_id','status','total_qty','uom'],
        ] as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->id(); $t->integer('entity_id');
                foreach ($columns as $column) $t->string($column)->nullable();
                $t->timestamps();
            });
        }
        DB::table('store_branches')->insert(['id'=>40,'entity_id'=>1]);
        DB::table('sap_masterfiles')->insert(['id'=>23835,'entity_id'=>1,'ItemCode'=>'199D9A','BaseUOM'=>'PC','AltUOM'=>'PC']);
        DB::table('month_end_schedules')->insert(['id'=>68,'entity_id'=>1,'calculated_date'=>'2026-08-31','month'=>8,'year'=>2026,'status'=>'level2_approved']);
        DB::table('month_end_count_items')->insert(['id'=>54366,'entity_id'=>1,'month_end_schedule_id'=>68,'branch_id'=>40,'sap_masterfile_id'=>23835,'status'=>'level2_approved','total_qty'=>8,'uom'=>'PC']);
        DB::table('product_inventory_stocks')->insert(['entity_id'=>1,'product_inventory_id'=>23835,'store_branch_id'=>40,'quantity'=>57]);
        $this->movement(1, 'add', 1, '2026-08-30');
        $this->movement(2, 'add', 5, '2026-09-01');
        $this->movement(3, 'out', 2, '2026-09-03');
        $this->movement(4, 'add', 12, '2026-09-16');
        $this->movement(5, 'out', 5, '2026-09-19');
    }

    private function movement(int $id, string $action, float $qty, string $date): void
    {
        DB::table('product_inventory_stock_managers')->insert(['id'=>$id,'entity_id'=>1,'product_inventory_id'=>23835,
            'store_branch_id'=>40,'action'=>$action,'quantity'=>$qty,'transaction_date'=>$date]);
    }

    public function test_count_uses_ledger_cutoff_and_preserves_later_activity_in_current_soh(): void
    {
        $service = new MonthEndStockAdjustment;
        DB::transaction(fn () => $service->post(23835,40,8,'2026-09-05','Approved August count'));
        $this->assertSame('1.0000000000', $service->balance(23835,40,'2026-08-31'));
        $this->assertSame('8.0000000000', $service->balance(23835,40,'2026-09-05'));
        $this->assertSame('15.0000000000', $service->balance(23835,40));
        $this->assertSame(15.0, (float) DB::table('product_inventory_stocks')->value('quantity'));
        $adjustment = DB::table('product_inventory_stock_managers')->where('remarks','Approved August count')->first();
        $this->assertSame(4.0,(float)$adjustment->quantity);
        $this->assertSame('add',$adjustment->action);
    }

    public function test_legacy_repair_is_preview_first_preserves_original_and_is_idempotent(): void
    {
        $this->movement(904190,'out',49,'2026-09-05');
        DB::table('product_inventory_stock_managers')->where('id',904190)->update([
            'is_stock_adjustment'=>1,'is_stock_adjustment_approved'=>1,'remarks'=>'August count||MEC_REF::68,40']);
        $original = (array) DB::table('product_inventory_stock_managers')->where('id',904190)->first();
        $this->artisan('stock:repair-month-end',['movement'=>904190])->assertExitCode(0);
        $this->assertSame(6,DB::table('product_inventory_stock_managers')->count());
        $this->assertSame('-38.0000000000',(new MonthEndStockAdjustment)->balance(23835,40));
        $this->artisan('stock:repair-month-end',['movement'=>904190,'--apply'=>true])->assertExitCode(0);
        $this->assertSame($original,(array) DB::table('product_inventory_stock_managers')->where('id',904190)->first());
        $this->assertSame(8,DB::table('product_inventory_stock_managers')->count());
        $this->assertSame('15.0000000000',(new MonthEndStockAdjustment)->balance(23835,40));
        $this->assertSame('8.0000000000',(new MonthEndStockAdjustment)->balance(23835,40,'2026-09-05'));
        $this->artisan('stock:repair-month-end',['movement'=>904190,'--apply'=>true])->assertExitCode(0);
        $this->assertSame(8,DB::table('product_inventory_stock_managers')->count());
        $this->assertCount(1,Storage::files('stock-repairs'));
    }

    public function test_repair_refuses_to_change_stock_when_a_later_count_exists(): void
    {
        $this->movement(904190,'out',49,'2026-09-05');
        DB::table('product_inventory_stock_managers')->where('id',904190)->update([
            'is_stock_adjustment'=>1,'is_stock_adjustment_approved'=>1,'remarks'=>'August count||MEC_REF::68,40']);
        DB::table('month_end_schedules')->insert(['id'=>69,'entity_id'=>1,'calculated_date'=>'2026-09-30']);
        DB::table('month_end_count_items')->insert(['entity_id'=>1,'month_end_schedule_id'=>69,'branch_id'=>40,'sap_masterfile_id'=>23835,'status'=>'level2_approved','total_qty'=>20,'uom'=>'PC']);
        $this->artisan('stock:repair-month-end',['movement'=>904190,'--apply'=>true])->assertExitCode(1);
        $this->assertSame(6,DB::table('product_inventory_stock_managers')->count());
    }

    public function test_repair_preserves_movements_after_approval_on_the_same_day(): void
    {
        $this->movement(904190,'out',49,'2026-09-05');
        DB::table('product_inventory_stock_managers')->where('id',904190)->update([
            'is_stock_adjustment'=>1,'is_stock_adjustment_approved'=>1,'remarks'=>'August count||MEC_REF::68,40']);
        $this->movement(904191,'add_quantity',3,'2026-09-05');
        $this->artisan('stock:repair-month-end',['movement'=>904190,'--apply'=>true])->assertExitCode(0);
        $this->assertSame('11.0000000000',(new MonthEndStockAdjustment)->balance(23835,40,'2026-09-05'));
        $this->assertSame('18.0000000000',(new MonthEndStockAdjustment)->balance(23835,40));
    }

    public function test_both_level_two_routes_use_the_same_ledger_posting_and_do_not_approve_twice(): void
    {
        Schema::table('month_end_count_items', function (Blueprint $t) {
            $t->integer('level2_approved_by')->nullable(); $t->timestamp('level2_approved_at')->nullable();
        });
        Schema::create('user_assigned_store_branches', function (Blueprint $t) {
            $t->id(); $t->integer('entity_id'); $t->integer('store_branch_id'); $t->integer('user_id');
        });
        $actor = \Mockery::mock(\App\Models\User::class)->makePartial();
        $actor->shouldReceive('can')->with('approve month end count level 2')->andReturn(true);
        \Illuminate\Support\Facades\Auth::shouldReceive('user')->andReturn($actor);
        \Illuminate\Support\Facades\Auth::shouldReceive('id')->andReturn(1);
        DB::table('month_end_count_items')->update(['status'=>'level1_approved']);
        \Illuminate\Support\Carbon::setTestNow('2026-09-05 12:00:00');
        try {
            app(\App\Http\Controllers\MonthEndCountApprovalController::class)->approveLevel2(68,40);
            $this->assertSame('8.0000000000',(new MonthEndStockAdjustment)->balance(23835,40,'2026-09-05'));
            $this->assertSame('level2_approved',DB::table('month_end_count_items')->value('status'));
            $this->assertSame(6,DB::table('product_inventory_stock_managers')->count());
            app(\App\Http\Controllers\MECApproval2Controller::class)->approveLevel2(68,40);
            $this->assertSame(6,DB::table('product_inventory_stock_managers')->count());
        } finally { \Illuminate\Support\Carbon::setTestNow(); }
    }

    public function test_bulk_repair_reconstructs_earlier_and_later_counts_without_double_adjusting(): void
    {
        $this->movement(904190,'out',49,'2026-09-05');
        DB::table('product_inventory_stock_managers')->where('id',904190)->update([
            'is_stock_adjustment'=>1,'is_stock_adjustment_approved'=>1,'remarks'=>'August count||MEC_REF::68,40']);
        DB::table('month_end_schedules')->insert(['id'=>69,'entity_id'=>1,'calculated_date'=>'2026-09-18']);
        DB::table('month_end_count_items')->insert(['entity_id'=>1,'month_end_schedule_id'=>69,'branch_id'=>40,'sap_masterfile_id'=>23835,'status'=>'level2_approved','total_qty'=>6,'uom'=>'PC']);
        $this->movement(904191,'out',3,'2026-09-18');
        DB::table('product_inventory_stock_managers')->where('id',904191)->update([
            'is_stock_adjustment'=>1,'is_stock_adjustment_approved'=>1,'remarks'=>'Later count||MEC_REF::69,40']);
        $repair = new \App\Services\MonthEndHistoryRepair;
        $preview = $repair->run(40,23835,false,'preview.json');
        $this->assertSame('1.0000000000',$preview['after']); // Latest count 6, then five sold.
        $this->assertCount(2,$preview['changes']);
        $this->assertSame(7,DB::table('product_inventory_stock_managers')->count());
        $applied = $repair->run(40,23835,true,'bulk-test.json');
        $this->assertSame('1.0000000000',(new MonthEndStockAdjustment)->balance(23835,40));
        $this->assertSame('8.0000000000',(new MonthEndStockAdjustment)->balance(23835,40,'2026-09-05'));
        $this->assertSame('6.0000000000',(new MonthEndStockAdjustment)->balance(23835,40,'2026-09-18'));
        $this->assertCount(2,$applied['movement_ids']);
        $this->assertSame('unchanged',$repair->run(40,23835,true,'repeat.json')['status']);
        $this->assertSame(9,DB::table('product_inventory_stock_managers')->count());
    }

    public function test_bulk_recognizes_existing_single_repair_and_database_guard(): void
    {
        $this->movement(904190,'out',49,'2026-09-05');
        DB::table('product_inventory_stock_managers')->where('id',904190)->update([
            'is_stock_adjustment'=>1,'is_stock_adjustment_approved'=>1,'remarks'=>'August count||MEC_REF::68,40']);
        $this->artisan('stock:repair-month-end',['movement'=>904190,'--apply'=>true])->assertExitCode(0);
        $repair = new \App\Services\MonthEndHistoryRepair;
        $this->assertSame('unchanged',$repair->run(40,23835,false,'existing.json')['status']);
        $this->artisan('stock:repair-month-end-all',['--apply'=>true,'--expect-database'=>'daviddb'])->assertExitCode(1);
        $this->artisan('stock:repair-month-end-all',['--expect-database'=>':memory:'])->assertExitCode(0);
        $this->assertSame(8,DB::table('product_inventory_stock_managers')->count());
    }

    public function test_bulk_missing_count_movement_blocks_whole_sequence_without_partial_writes(): void
    {
        $this->movement(904190,'out',49,'2026-09-05');
        DB::table('product_inventory_stock_managers')->where('id',904190)->update([
            'is_stock_adjustment'=>1,'is_stock_adjustment_approved'=>1,'remarks'=>'August count||MEC_REF::68,40']);
        DB::table('month_end_schedules')->insert(['id'=>69,'entity_id'=>1,'calculated_date'=>'2026-09-18']);
        DB::table('month_end_count_items')->insert(['entity_id'=>1,'month_end_schedule_id'=>69,'branch_id'=>40,'sap_masterfile_id'=>23835,'status'=>'level2_approved','total_qty'=>6,'uom'=>'PC']);
        $this->artisan('stock:repair-month-end-all',['--apply'=>true,'--expect-database'=>':memory:'])->assertExitCode(2);
        $this->assertSame(6,DB::table('product_inventory_stock_managers')->count());
    }
}
