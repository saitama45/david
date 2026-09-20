<?php

namespace Tests\Unit;

use App\Imports\StoreTransactionImport;
use App\Services\PosReceiptMapper;
use App\Services\PosSalesSource;
use App\Services\PosSalesSync;
use App\Support\EntityContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Dedicated in-memory integration coverage; never loads the project's databases. */
class PosSalesSyncTest extends TestCase
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
            $app['config']->set('pos_sync.connection', 'sqlite');
        });
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        app(EntityContext::class)->set(1);
        Schema::create('store_orders', function (Blueprint $table) {
            $table->id(); $table->integer('entity_id'); $table->integer('store_branch_id');
            $table->string('variant'); $table->timestamps();
        });
        DB::table('store_orders')->insert(['entity_id' => 1, 'store_branch_id' => 1, 'variant' => 'mass regular', 'created_at' => '2026-09-01 12:00:00']);
        foreach ([
            'store_branches' => ['location_code', 'is_active', 'branch_code'],
            'pos_masterfiles' => ['POSCode', 'POSDescription'],
            'pos_masterfiles_bom' => ['POSCode', 'ItemCode', 'ItemDescription', 'BOMUOM', 'BOMQty', 'UnitCost'],
            'sap_masterfiles' => ['ItemCode', 'ItemDescription', 'BaseUOM', 'AltUOM'],
            'store_transactions' => ['store_branch_id', 'order_date', 'posted', 'tim_number', 'receipt_number'],
            'store_transaction_items' => ['store_transaction_id', 'product_id', 'base_quantity', 'quantity', 'price', 'discount', 'line_total', 'net_total', 'take_out'],
            'product_inventory_stock_managers' => ['product_inventory_id', 'store_branch_id', 'cost_center_id', 'quantity', 'action', 'unit_cost', 'total_cost', 'transaction_date', 'remarks'],
        ] as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns) {
                $table->id();
                $table->unsignedBigInteger('entity_id');
                foreach ($columns as $column) {
                    $table->string($column)->nullable();
                }
                $table->timestamps();
            });
        }
        (require base_path('database/migrations/2026_09_20_000001_create_pos_sync_receipts_table.php'))->up();
        (require base_path('database/migrations/2026_09_20_000003_create_sales_posting_controls.php'))->up();
        (require base_path('database/migrations/2026_09_20_000004_add_fingerprint_to_pos_sync_exceptions.php'))->up();
        DB::table('store_branches')->insert(['id' => 1, 'entity_id' => 1, 'location_code' => 'TEST', 'is_active' => 1, 'branch_code' => 'TEST']);
        DB::table('pos_masterfiles')->insert(['id' => 10, 'entity_id' => 1, 'POSCode' => 'COFFEE', 'POSDescription' => 'Coffee']);
        DB::table('sap_masterfiles')->insert(['id' => 20, 'entity_id' => 1, 'ItemCode' => 'BEANS', 'ItemDescription' => 'Beans', 'BaseUOM' => 'KG', 'AltUOM' => 'KG']);
        DB::table('pos_masterfiles_bom')->insert(['entity_id' => 1, 'POSCode' => 'COFFEE', 'ItemCode' => 'BEANS', 'ItemDescription' => 'Beans', 'BOMUOM' => 'KG', 'BOMQty' => '0.5', 'UnitCost' => '10']);
        DB::table('product_inventory_stock_managers')->insert(['entity_id' => 1, 'product_inventory_id' => 20, 'store_branch_id' => 1, 'action' => 'add', 'quantity' => 10]);
    }

    public function test_sales_status_visibility_and_completion_notifications_are_scoped(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id(); $table->integer('entity_id'); $table->integer('user_id');
            $table->string('type'); $table->string('status'); $table->string('original_filename');
            $table->integer('processed_count')->default(0); $table->integer('skipped_count')->default(0);
            $table->timestamp('completed_at')->nullable(); $table->timestamps();
        });
        Schema::create('import_log_store_branches', function (Blueprint $table) {
            $table->integer('import_log_id'); $table->integer('store_branch_id');
        });
        Schema::create('user_assigned_store_branches', function (Blueprint $table) {
            $table->integer('user_id'); $table->integer('store_branch_id');
        });
        DB::table('store_branches')->insert([
            ['id' => 2, 'entity_id' => 1, 'location_code' => 'OTHER'],
            ['id' => 3, 'entity_id' => 2, 'location_code' => 'OTHER-ENTITY'],
        ]);
        DB::table('user_assigned_store_branches')->insert(['user_id' => 7, 'store_branch_id' => 1]);
        foreach ([
            [1, 1, 99, 'pos_sales', 0, [1]],
            [2, 1, 99, 'pos_sales', 2, [2]],
            [3, 1, 99, 'pos_sales', 0, [1, 2]],
            [4, 1, 7, 'store_transaction', 3, []],
            [5, 2, 7, 'store_transaction', 0, [3]],
            [6, 1, 99, 'store_transaction', 0, [1]],
            [7, 1, 99, 'pos_sales', 0, [1, 3]],
            [8, 1, 99, 'pos_sales', 2, [1]],
        ] as [$id, $entity, $owner, $type, $skipped, $branches]) {
            DB::table('import_logs')->insert(['id' => $id, 'entity_id' => $entity, 'user_id' => $owner,
                'type' => $type, 'status' => 'completed', 'original_filename' => 'sales.xlsx',
                'skipped_count' => $skipped, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($branches as $branch) {
                DB::table('import_log_store_branches')->insert(['import_log_id' => $id, 'store_branch_id' => $branch]);
            }
        }
        $user = \Mockery::mock(\App\Models\User::class)->makePartial();
        $user->id = 7;
        $user->shouldReceive('hasRole')->with('admin')->andReturn(false);
        $user->shouldReceive('can')->with('view store transactions')->andReturn(true);
        $service = new \App\Services\SalesImportStatus;
        $this->assertSame([1, 4, 8], $service->visibleQuery($user)->orderBy('id')->pluck('id')->all());
        $this->assertNull($service->visibleQuery($user)->find(3)); // Same query protects downloads.
        $notifications = $service->completions($user);
        $this->assertSame([4], array_column($notifications, 'id'));
        $this->assertSame('completed_with_issues', $notifications[0]['status']);
        DB::table('store_transactions')->insert([
            ['entity_id' => 1, 'store_branch_id' => 1, 'order_date' => '2026-09-18'],
            ['entity_id' => 1, 'store_branch_id' => 2, 'order_date' => '2026-09-20'],
        ]);
        $summary = $service->summary($user);
        $this->assertSame('2026-09-18', $summary['latest_sales_date']);
        $this->assertSame(8, $summary['latest_run']['id']);
        $this->assertSame('completed_with_issues', $summary['latest_run']['status']);
        $this->assertNotNull($summary['last_successful_sync']);
        $this->assertNull($service->summary($user, 2)['latest_run']);
        $this->assertNull($service->summary($user, 2)['latest_sales_date']);
        $this->assertSame('completed', (new \App\Models\ImportLog(['status' => 'completed', 'skipped_count' => 0]))->display_status);
        $this->assertSame('failed', (new \App\Models\ImportLog(['status' => 'failed', 'skipped_count' => 3]))->display_status);
    }


    public function test_manual_day_guard_is_scoped_to_store_and_business_date(): void
    {
        $packet = $this->packet();
        $this->assertSame('imported', (new PosSalesSync)->process($packet, $this->profile(), 123)['status']);
        $rows = $packet['rows'];
        $rows[0]['receipt_no'] = '43';
        $rows[0]['tm'] = '0099';
        $manual = new \App\Services\StoreTransactionReceiptProcessor;
        $this->assertNull($manual->processReceiptGroup(collect($rows)));
        $this->assertStringContainsString('already has automated sales', $manual->getSkippedRows()[0]['reason']);
        $rows[0]['date'] = '2026-09-18';
        $this->assertNotNull((new \App\Services\StoreTransactionReceiptProcessor)->processReceiptGroup(collect($rows)));
        DB::table('store_branches')->insert(['id' => 2, 'entity_id' => 1, 'location_code' => 'OTHER']);
        $rows = $packet['rows']; $rows[0]['__branch_id'] = 2; $rows[0]['branch'] = 'OTHER';
        $this->assertNotNull((new \App\Services\StoreTransactionReceiptProcessor)->processReceiptGroup(collect($rows)));
        $this->assertSame(3, DB::table('store_transactions')->count());
    }

    public function test_manual_first_at_zero_stock_is_linked_once_despite_receipt_padding(): void
    {
        DB::table('product_inventory_stock_managers')->update(['quantity' => 0]);
        $packet = $this->packet();
        $rows = $packet['rows']; $rows[0]['tm'] = '41'; $rows[0]['receipt_no'] = '41-00000042';
        $this->assertNotNull((new \App\Services\StoreTransactionReceiptProcessor)->processReceiptGroup(collect($rows)));
        $this->assertSame(1.0, (float) DB::table('product_inventory_stock_managers')->where('action', 'out')->sum('quantity'));
        $sync = new PosSalesSync;
        $this->assertSame('linked', $sync->process($packet, $this->profile(), 123)['status']);
        $this->assertSame('unchanged', $sync->process($packet, $this->profile(), 124)['status']);
        $this->assertSame(1, DB::table('store_transactions')->count());
        $this->assertNotNull(DB::table('sales_postings')->value('pos_verified_at'));
        $rows[0]['receipt_no'] = '43';
        $this->assertNull((new \App\Services\StoreTransactionReceiptProcessor)->processReceiptGroup(collect($rows)));
    }

    public function test_excel_grouping_preserves_store_date_and_terminal(): void
    {
        $import = new StoreTransactionImport;
        $import->collection(collect([
            ['Product ID','Branch','Date','Receipt No','TM#','Qty','Base Qty','Price','Discount','Line Total','Net Total'],
            ['COFFEE','TEST','2026-09-18','42','0041',1,1,100,0,100,100],
            ['COFFEE','TEST','2026-09-19','42','0041',1,1,100,0,100,100],
            ['COFFEE','TEST','2026-09-19','42','0042',1,1,100,0,100,100],
        ]));
        $this->assertSame(3, $import->getCreatedCount());
        $this->assertCount(0, $import->getSkippedRows());
        $this->assertSame(3, DB::table('sales_postings')->count());
    }

    public function test_manual_negative_quantity_and_missing_bom_never_post(): void
    {
        $rows = $this->packet()['rows']; $rows[0]['qty'] = -2;
        $this->assertNull((new \App\Services\StoreTransactionReceiptProcessor)->processReceiptGroup(collect($rows)));
        DB::table('pos_masterfiles_bom')->delete();
        $sync = new PosSalesSync;
        $this->assertSame('review', $sync->process($this->packet(), $this->profile(), 123)['status']);
        $this->assertSame(1, DB::table('pos_sync_exceptions')->whereNull('resolved_at')->count());
        $this->assertSame(0, DB::table('store_transactions')->count());
        config(['sales_posting.non_inventory_products.1' => ['COFFEE']]);
        $this->assertSame('imported', $sync->process($this->packet(), $this->profile(), 124)['status']);
        $this->assertSame(0, DB::table('pos_sync_exceptions')->whereNull('resolved_at')->count());
        $this->assertSame(0, DB::table('product_inventory_stock_managers')->where('action', 'out')->count());
    }

    public function test_destination_tampering_and_legacy_unverified_receipts_require_review(): void
    {
        $sync = new PosSalesSync;
        $this->assertSame('imported', $sync->process($this->packet(), $this->profile(), 123)['status']);
        DB::table('store_transaction_items')->update(['quantity' => 99]);
        $this->assertSame('review', $sync->process($this->packet(), $this->profile(), 124)['status']);
        DB::table('pos_sync_receipts')->delete();
        DB::table('sales_postings')->delete();
        $this->assertSame('review', $sync->process($this->packet(), $this->profile(), 125)['status']);
        $this->assertSame(1, DB::table('store_transactions')->count());
    }

    public function test_pause_blocks_new_manual_and_pos_postings(): void
    {
        \Illuminate\Support\Facades\Cache::forever('sales-posting:paused', true);
        $this->assertNull((new \App\Services\StoreTransactionReceiptProcessor)->processReceiptGroup(collect($this->packet()['rows'])));
        $this->expectExceptionMessage('Sales posting is paused');
        (new PosSalesSync)->process($this->packet(), $this->profile(), 123);
    }

    public function test_correction_reverses_original_consumption_and_keeps_day_guard(): void
    {
        $packet = $this->packet();
        (new PosSalesSync)->process($packet, $this->profile(), 123);
        $actor = \Mockery::mock(\App\Models\User::class)->makePartial();
        $actor->id = 7;
        $actor->shouldReceive('can')->with('edit store transactions')->andReturn(true);
        $actor->shouldReceive('hasRole')->with('admin')->andReturn(true);
        $rows = $packet['rows']; $rows[0]['qty'] = 4; $rows[0]['base_qty'] = 4;
        $sale = \App\Models\StoreTransaction::first();
        (new \App\Services\SalesPostingCorrection)->replace($sale, $rows, $actor, 'Correct quantity from verified receipt');
        $this->assertSame(1, DB::table('store_transactions')->count());
        $this->assertSame(4.0, (float) DB::table('store_transaction_items')->value('quantity'));
        $this->assertSame(1, DB::table('sales_posting_corrections')->count());
        $this->assertSame(3.0, (float) DB::table('product_inventory_stock_managers')->where('action', 'out')->sum('quantity'));
        $this->assertSame(11.0, (float) DB::table('product_inventory_stock_managers')->where('action', 'add')->sum('quantity'));
        $this->assertTrue((new \App\Services\SalesPostingLedger)->syncedDay(1, 1, '2026-09-19'));
        $this->assertSame('review', (new PosSalesSync)->process($packet, $this->profile(), 124)['status']);
        $changedSource = $packet;
        $changedSource['rows'] = $rows;
        $changedSource['hash'] = hash('sha256', json_encode($rows));
        $this->assertSame('reconciled', (new PosSalesSync)->process($changedSource, $this->profile(), 125)['status']);
        $this->assertSame(0, DB::table('pos_sync_exceptions')->whereNull('resolved_at')->count());
        $this->assertStringStartsWith('Sale #'.$sale->id.',', DB::table('product_inventory_stock_managers')->where('action', 'out')->orderByDesc('id')->value('remarks'));
        // A rejected second correction must roll back its reversal as well.
        DB::table('pos_masterfiles_bom')->delete();
        try {
            (new \App\Services\SalesPostingCorrection)->replace($sale, $rows, $actor, 'This correction cannot post without a BOM');
            $this->fail('Expected missing BOM rejection');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Missing BOM', $e->getMessage());
        }
        $this->assertSame(1, DB::table('sales_posting_corrections')->count());
        $this->assertSame(11.0, (float) DB::table('product_inventory_stock_managers')->where('action', 'add')->sum('quantity'));
    }

    public function test_manual_actor_cannot_post_an_unassigned_store(): void
    {
        Schema::create('user_assigned_store_branches', function (Blueprint $table) {
            $table->integer('user_id'); $table->integer('store_branch_id');
        });
        $actor = \Mockery::mock(\App\Models\User::class)->makePartial(); $actor->id = 7;
        $actor->shouldReceive('hasRole')->with('admin')->andReturn(false);
        $processor = new \App\Services\StoreTransactionReceiptProcessor($actor);
        $this->assertNull($processor->processReceiptGroup(collect($this->packet()['rows'])));
        $this->assertStringContainsString('not authorized', $processor->getSkippedRows()[0]['reason']);
        $this->assertSame(0, DB::table('store_transactions')->count());
    }


    public function test_invalid_identity_rejects_file_before_any_partial_receipt_posts(): void
    {
        $import = new StoreTransactionImport;
        $import->collection(collect([
            ['Product ID','Branch','Date','Receipt No','TM#','Qty','Base Qty','Price','Discount','Line Total','Net Total'],
            ['COFFEE','TEST','2026-09-18','42','0041',1,1,100,0,100,100],
            ['COFFEE','TEST','not-a-date','42','0041',1,1,100,0,100,100],
        ]));
        $this->assertSame(0, $import->getCreatedCount());
        $this->assertCount(2, $import->getSkippedRows());
        $this->assertSame(0, DB::table('store_transactions')->count());
    }

    public function test_alternate_bom_unit_is_converted_to_stock_base_unit(): void
    {
        Schema::table('sap_masterfiles', function (Blueprint $table) {
            $table->decimal('BaseQty', 12, 4)->nullable(); $table->decimal('AltQty', 12, 4)->nullable();
        });
        DB::table('sap_masterfiles')->update(['BaseUOM' => 'KG', 'AltUOM' => 'KG', 'BaseQty' => 1, 'AltQty' => 1]);
        DB::table('sap_masterfiles')->insert(['id' => 21, 'entity_id' => 1, 'ItemCode' => 'BEANS', 'ItemDescription' => 'Beans in grams', 'BaseUOM' => 'KG', 'AltUOM' => 'G', 'BaseQty' => 0.001, 'AltQty' => 1]);
        DB::table('pos_masterfiles_bom')->update(['BOMUOM' => 'G', 'BOMQty' => 500, 'UnitCost' => 0.01]);
        $this->assertSame('imported', (new PosSalesSync)->process($this->packet(), $this->profile(), 123)['status']);
        $this->assertSame(1.0, (float) DB::table('product_inventory_stock_managers')->where('action', 'out')->sum('quantity'));
        $this->assertSame(10.0, (float) DB::table('product_inventory_stock_managers')->where('action', 'out')->sum('total_cost'));
        $this->assertSame(20, (int) DB::table('product_inventory_stock_managers')->where('action', 'out')->value('product_inventory_id'));
    }

    public function test_movement_tampering_does_not_pass_source_replay_validation(): void
    {
        $sync = new PosSalesSync;
        $this->assertSame('imported', $sync->process($this->packet(), $this->profile(), 123)['status']);
        DB::table('product_inventory_stock_managers')->where('action', 'out')->update(['quantity' => 999]);
        $this->assertSame('review', $sync->process($this->packet(), $this->profile(), 124)['status']);
    }

    public function test_excel_reader_uses_receipt_transactions_and_preserves_dates(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $path = 'sales.csv';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path,
            "Product ID,Branch,Date,Receipt No,TM#,Qty,Base Qty,Price,Discount,Line Total,Net Total\nCOFFEE,TEST,2026-09-19,42,0041,2,2,100,20,180,180\n");
        $import = new class extends StoreTransactionImport {
            public array $levels = [];
            public function processReceiptGroup(\Illuminate\Support\Collection $rows): ?\App\Models\StoreTransaction {
                $this->levels[] = DB::transactionLevel();
                return parent::processReceiptGroup($rows);
            }
        };
        $reader = new \Maatwebsite\Excel\Reader(app(\Maatwebsite\Excel\Files\TemporaryFileFactory::class), new \Maatwebsite\Excel\Transactions\NullTransactionHandler);
        $reader->read($import, \Illuminate\Support\Facades\Storage::disk('local')->path($path));
        $this->assertSame([0], $import->levels);
        $this->assertSame(1, $import->getCreatedCount());
        $this->assertSame('2026-09-19', substr(DB::table('store_transactions')->value('order_date'), 0, 10));
    }


    public function test_two_pos_terminals_can_reuse_receipt_number_on_same_day(): void
    {
        $sync = new PosSalesSync;
        $packet = $this->packet();
        $this->assertSame('imported', $sync->process($packet, $this->profile(), 123)['status']);
        $packet['key'] = hash('sha256', 'second-terminal-source');
        $packet['identity'][3] = '2';
        $packet['rows'][0]['tm'] = '0099';
        $packet['hash'] = hash('sha256', json_encode($packet['rows']));
        $this->assertSame('imported', $sync->process($packet, $this->profile(), 124)['status']);
        $this->assertSame(2, DB::table('store_transactions')->count());
        $this->assertSame(2.0, (float) DB::table('product_inventory_stock_managers')->where('action', 'out')->sum('quantity'));
    }

    public function test_review_only_sync_does_not_block_manual_import(): void
    {
        $packet = $this->packet(); $packet['error'] = 'Source receipt still syncing';
        $this->assertSame('review', (new PosSalesSync)->process($packet, $this->profile(), 123)['status']);
        $this->assertFalse((new \App\Services\SalesPostingLedger)->syncedDay(1, 1, '2026-09-19'));
        $this->assertNotNull((new \App\Services\StoreTransactionReceiptProcessor)->processReceiptGroup(collect($packet['rows'])));
    }

    public function test_mixed_excel_file_reports_blocked_day_and_posts_eligible_day(): void
    {
        (new PosSalesSync)->process($this->packet(), $this->profile(), 123);
        $import = new StoreTransactionImport;
        $import->collection(collect([
            ['Product ID','Branch','Date','Receipt No','TM#','Qty','Base Qty','Price','Discount','Line Total','Net Total'],
            ['COFFEE','TEST','2026-09-19','99','0041',1,1,100,0,100,100],
            ['COFFEE','TEST','2026-09-18','99','0041',1,1,100,0,100,100],
        ]));
        $this->assertSame(1, $import->getCreatedCount());
        $this->assertCount(1, $import->getSkippedRows());
        $this->assertStringContainsString('Manual import blocked', $import->getSkippedRows()[0]['reason']);
    }

    public function test_direct_create_posts_consumption_without_shifting_business_date(): void
    {
        $actor = \Mockery::mock(\App\Models\User::class)->makePartial(); $actor->id = 7;
        $actor->shouldReceive('hasRole')->with('admin')->andReturn(true);
        $this->actingAs($actor);
        (new \App\Http\Services\StoreTransactionService)->createStoreTransaction([
            'store_branch_id' => 1, 'order_date' => '2026-09-19', 'posted' => '1244', 'tim_number' => '0041', 'receipt_number' => '42',
            'items' => [['product_id' => 10, 'quantity' => 2, 'price' => 100, 'discount' => 20, 'line_total' => 180, 'net_total' => 180]],
        ]);
        $this->assertSame('2026-09-19', \App\Models\StoreTransaction::first()->order_date->format('Y-m-d'));
        $this->assertSame(1.0, (float) DB::table('product_inventory_stock_managers')->where('action', 'out')->sum('quantity'));
    }


    public function test_apply_command_establishes_entity_context_before_posting_checks(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id(); $table->integer('entity_id'); $table->string('type');
            $table->string('status'); $table->string('original_filename');
        });
        $profile = $this->profile();
        $mock = $this->mock(PosSalesSync::class);
        $mock->shouldReceive('dispatchPending')->once();
        $mock->shouldReceive('profile')->with('test', true)->once()->andReturn($profile);
        $mock->shouldReceive('enqueue')->once()->withArgs(function ($name, $received, $from, $to, $window) use ($profile) {
            return $name === 'test' && $received === $profile && app(EntityContext::class)->id() === 1
                && $from === '2026-09-19' && $to === '2026-09-19' && $window === null;
        })->andReturn((new \App\Models\ImportLog)->forceFill(['id' => 999]));
        app(EntityContext::class)->clear();
        $this->artisan('pos:sync-sales', ['--profile' => 'test', '--from' => '2026-09-19', '--to' => '2026-09-19', '--apply' => true])
            ->expectsOutput('test: queued as Work Queue #999.')->assertExitCode(0);
        $this->assertNull(app(EntityContext::class)->id());
    }

    public function test_preview_checks_missing_recipes_without_creating_sales_or_exceptions(): void
    {
        DB::table('pos_masterfiles_bom')->delete();
        $result = (new PosSalesSync)->process($this->packet(), $this->profile());
        $this->assertSame('review', $result['status']);
        $this->assertStringContainsString('Missing BOM', $result['reason']);
        $this->assertSame(0, DB::table('store_transactions')->count());
        $this->assertSame(0, DB::table('pos_sync_exceptions')->count());
    }

    private function profile(): array
    {
        return ['entity_id' => 1, 'branch_id' => 1, 'company' => 'COMPANY', 'site' => 'TEST',
            'receipt_field' => 'ftrx_no', 'receipt_format' => '{terminal}-{receipt}', 'receipt_padding' => 8,
            'base_quantity' => 'quantity_times_uom', 'discount_field' => 'ftotal_discount',
            'line_total_field' => 'fextprice', 'net_total_field' => 'ftotal_line', 'posted_field' => 'fsale_time',
            'take_out_field' => 'fhsmode_flag', 'take_out_values' => ['1'], 'dine_in_values' => ['0', ''], 'line_status_values' => ['1']];
    }

    private function sale(): array
    {
        return ['fcompanyid' => 'COMPANY', 'fpubid' => 'PUB', 'frecno' => '1', 'fsiteid' => 'TEST',
            'fpost_flag' => '1', 'fvoid_flag' => '0', 'freturn_flag' => '0', 'fsale_date' => '20260919',
            'fsale_time' => '1200', 'fposted_date' => '20260919124400', 'fsubtotal' => '180', 'fgross' => '180', 'fservice_charge' => '0', 'ftermid' => '0041', 'ftrx_no' => '42', 'fhsmode_flag' => '1', 'ftotal_qty' => '2'];
    }

    public function test_pos_posting_requires_dashboard_go_live_and_rechecks_each_receipt(): void
    {
        $sync = new PosSalesSync;
        $profile = $this->profile() + ['start_date' => '2026-09-14'];
        $eligibility = new \App\Services\PosSalesEligibility;
        $this->assertSame('2026-09-14', $eligibility->startDate($profile));
        $oldKey = $sync->cursorKey($profile);
        DB::table('store_orders')->delete();
        DB::table('store_orders')->insert(['entity_id' => 2, 'store_branch_id' => 1, 'variant' => 'mass regular', 'created_at' => '2026-09-01']);
        DB::table('store_orders')->insert(['entity_id' => 1, 'store_branch_id' => 1, 'variant' => 'interco', 'created_at' => '2026-09-01']);
        $this->assertNull($eligibility->startDate($profile));
        $this->assertSame('ineligible', $sync->process($this->packet(), $profile, 123)['status']);
        $this->assertSame(0, DB::table('sales_postings')->count());
        $this->assertSame(0, DB::table('pos_sync_exceptions')->count());
        $this->assertSame(0, DB::table('store_transactions')->count());
        DB::table('store_orders')->insert(['entity_id' => 1, 'store_branch_id' => 1, 'variant' => 'mass regular', 'created_at' => '2026-09-20']);
        $this->assertSame('2026-09-20', $eligibility->startDate($profile));
        $this->assertNotSame($oldKey, $sync->cursorKey($profile));
        $this->assertSame('ineligible', $sync->process($this->packet(), $profile, 123)['status']); // September 19 precedes go-live.
        DB::table('store_orders')->where('entity_id', 1)->where('variant', 'mass regular')->update(['created_at' => '2026-09-19']);
        $this->assertSame('imported', $sync->process($this->packet(), $profile, 123)['status']);
        DB::table('store_branches')->where('id', 1)->update(['is_active' => 0]);
        $this->assertNull($eligibility->startDate($profile));
        $this->assertSame(1, DB::table('store_transactions')->count());
        $this->assertNotSame($sync->cursorKey($this->profile() + ['start_date' => '2026-09-18']), $oldKey);
    }

    public function test_missing_bom_report_scans_all_sold_codes_and_removes_resolved_or_exempt_codes(): void
    {
        Schema::create('mst_product', function (Blueprint $table) {
            foreach (['fcompanyid', 'fproductid', 'fname', 'fupdated_date', '_sync_timestamp'] as $column) $table->string($column)->nullable();
        });
        DB::table('mst_product')->insert(['fcompanyid' => 'COMPANY', 'fproductid' => 'MISSING', 'fname' => 'New beverage']);
        config(['pos_sync.profiles' => ['test' => $this->profile() + ['start_date' => '2026-09-20']],
            'sales_posting.non_inventory_products.1' => ['EXEMPT']]);
        foreach (['pos_sale' => $this->sale(), 'pos_sale_product' => $this->line()] as $name => $sample) {
            Schema::create($name, function (Blueprint $table) use ($sample) {
                foreach (array_keys($sample) as $column) $table->string($column);
                $table->dateTime('_sync_timestamp');
            });
        }
        $header = $this->sale() + ['_sync_timestamp' => '2026-09-19 13:00:00'];
        DB::table('pos_sale')->insert([$header, $header]);
        foreach (['COFFEE', 'MISSING', 'EXEMPT', 'REMOVED'] as $i => $code) {
            $line = array_replace($this->line(), ['fseqno' => (string) ($i + 1), 'fproductid' => $code,
                'fstatus_flag' => $code === 'REMOVED' ? '0' : '1', '_sync_timestamp' => '2026-09-19 13:00:00']);
            DB::table('pos_sale_product')->insert([$line, $line]);
        }
        DB::table('pos_masterfiles_bom')->delete();
        DB::table('store_transactions')->insert(['id' => 99, 'entity_id' => 1, 'store_branch_id' => 1, 'order_date' => '2026-09-19']);
        DB::table('store_transaction_items')->insert(['entity_id' => 1, 'store_transaction_id' => 99, 'product_id' => 10]);
        $service = new \App\Services\MissingPosBomReport;
        $report = $service->build([1], '2026-09-19', '2026-09-19');
        $this->assertSame(2, $report['codes']);
        $this->assertSame(['COFFEE', 'MISSING'], array_column($report['rows'], 'code'));
        $this->assertSame(1, $report['rows'][0]['source_receipts']);
        $this->assertSame(1, $report['rows'][0]['imported_receipts']);
        $this->assertSame('Missing POS masterfile', $report['rows'][1]['issue']);
        $this->assertSame('New beverage', $report['rows'][1]['description']);
        $this->assertCount(2, $report['distinct_rows']);
        $this->assertSame(1, $report['distinct_rows'][0]['store_count']);
        DB::table('store_branches')->insert(['id' => 2, 'entity_id' => 1, 'branch_code' => 'SECOND']);
        DB::table('store_transactions')->insert(['id' => 100, 'entity_id' => 1, 'store_branch_id' => 2, 'order_date' => '2026-09-19']);
        DB::table('store_transaction_items')->insert(['entity_id' => 1, 'store_transaction_id' => 100, 'product_id' => 10]);
        $combined = $service->build([1, 2], '2026-09-19', '2026-09-19');
        $this->assertCount(2, $combined['distinct_rows']);
        $this->assertSame(2, $combined['distinct_rows'][0]['store_count']);
        $this->assertSame(2, $combined['distinct_rows'][0]['imported_receipts']);
        $this->assertSame(1, $combined['distinct_rows'][0]['source_receipts']);
        $this->assertSame(1, $service->build([1], '2026-09-19', '2026-09-19', 'beverage')['codes']);
        $this->assertSame([], $service->build([], '2026-09-19', '2026-09-19')['rows']);
        DB::table('pos_masterfiles_bom')->insert(['entity_id' => 2, 'POSCode' => 'COFFEE']);
        $this->assertSame(2, $service->build([1], '2026-09-19', '2026-09-19')['codes']);
        DB::table('pos_masterfiles_bom')->insert(['entity_id' => 1, 'POSCode' => 'COFFEE']);
        $this->assertSame(['MISSING'], array_column($service->build([1], '2026-09-19', '2026-09-19')['rows'], 'code'));
        DB::table('pos_masterfiles_bom')->insert(['entity_id' => 1, 'POSCode' => 'MISSING']);
        $resolvedRecipe = $service->build([1], '2026-09-19', '2026-09-19');
        $this->assertCount(1, $resolvedRecipe['rows']); // Masterfile still needs attention.
        $this->assertSame([], $resolvedRecipe['distinct_rows']); // But this code now has a recipe.
        $this->assertSame(2, DB::table('store_transactions')->count());
        $this->assertSame(1, DB::table('product_inventory_stock_managers')->count());
    }

    private function line(): array
    {
        return ['fcompanyid' => 'COMPANY', 'fpubid' => 'PUB', 'frecno' => '1', 'fsiteid' => 'TEST',
            'ftermid' => '0041', 'fsale_date' => '20260919', 'fseqno' => '1', 'fstatus_flag' => '1',
            'fdeliver_flag' => '1', 'fvar' => '0', 'fqty' => '2', 'fuomqty' => '1', 'fproductid' => 'COFFEE', 'fuom' => 'PC',
            'funitprice' => '100', 'ftotal_discount' => '20', 'fextprice' => '200', 'ftotal_line' => '180'];
    }

    private function packet(): array
    {
        $rows = (new PosReceiptMapper)->map($this->sale(), [$this->line()], $this->profile());
        return ['identity' => ['COMPANY', 'TEST', 'PUB', '1'], 'key' => hash('sha256', 'receipt-1'),
            'rows' => $rows, 'hash' => hash('sha256', json_encode($rows)), 'error' => null];
    }

    public function test_idle_scan_reuses_exception_and_detects_bom_repair_without_source_change(): void
    {
        $sync = new PosSalesSync;
        $packet = $this->packet();
        $bom = (array) DB::table('pos_masterfiles_bom')->first();
        DB::table('pos_masterfiles_bom')->delete();
        $this->mock(PosSalesSource::class)->shouldReceive('receipts')->andReturnUsing(function () use ($packet) {
            yield $packet;
        });
        $this->assertTrue($sync->hasPendingWork($this->profile(), '2026-09-14', '2026-09-20'));
        $this->assertSame('review', $sync->process($packet, $this->profile(), 123)['status']);
        DB::table('pos_sync_exceptions')->update(['retry_at' => now()->subMinute()]);
        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse($sync->hasPendingWork($this->profile(), '2026-09-14', '2026-09-20'));
        }
        $this->assertSame(1, DB::table('pos_sync_exceptions')->count());
        $this->assertSame(1, (int) DB::table('pos_sync_exceptions')->value('attempts'));
        $this->assertTrue(\Carbon\Carbon::parse(DB::table('pos_sync_exceptions')->value('retry_at'))->isFuture());
        $this->assertSame(0, DB::table('store_transactions')->count());
        DB::table('pos_masterfiles_bom')->insert($bom);
        $this->assertTrue($sync->hasPendingWork($this->profile(), '2026-09-14', '2026-09-20'));
        $this->assertSame('imported', $sync->process($packet, $this->profile(), 124)['status']);
        $this->assertFalse($sync->hasPendingWork($this->profile(), '2026-09-14', '2026-09-20'));
        $this->assertSame(1, DB::table('store_transactions')->count());
    }

    public function test_quiet_scan_still_detects_changed_source_and_destination_damage(): void
    {
        $sync = new PosSalesSync;
        $packet = $this->packet();
        $this->mock(PosSalesSource::class)->shouldReceive('receipts')->andReturnUsing(function () use (&$packet) {
            yield $packet;
        });
        $sync->process($packet, $this->profile(), 123);
        $this->assertFalse($sync->hasPendingWork($this->profile(), '2026-09-14', '2026-09-20'));
        $packet['hash'] = hash('sha256', 'revision-2');
        $this->assertTrue($sync->hasPendingWork($this->profile(), '2026-09-14', '2026-09-20'));
        $sync->process($packet, $this->profile(), 124);
        $this->assertFalse($sync->hasPendingWork($this->profile(), '2026-09-14', '2026-09-20'));
        $packet['hash'] = hash('sha256', 'revision-3');
        $this->assertTrue($sync->hasPendingWork($this->profile(), '2026-09-14', '2026-09-20'));
        $packet = $this->packet();
        // Restoring a receipt also queues resolution of its outstanding issue.
        $this->assertTrue($sync->hasPendingWork($this->profile(), '2026-09-14', '2026-09-20'));
        $sync->process($packet, $this->profile(), 125);
        DB::table('product_inventory_stock_managers')->where('action', 'out')->update(['quantity' => 9]);
        $this->assertTrue($sync->hasPendingWork($this->profile(), '2026-09-14', '2026-09-20'));
    }

    public function test_scheduled_idle_scan_advances_cursor_without_enqueueing(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id(); $table->integer('entity_id'); $table->string('type');
            $table->string('status'); $table->string('original_filename');
        });
        (require base_path('database/migrations/2026_09_20_000002_create_pos_sync_cursors_table.php'))->up();
        $profile = $this->profile();
        config(['pos_sync.enabled' => true, 'pos_sync.profiles' => ['test' => $profile]]);
        $this->mock(PosSalesSource::class)->shouldReceive('receipts')->once()->andReturnUsing(function () {
            yield from [];
        });
        $sync = $this->partialMock(PosSalesSync::class);
        $sync->shouldReceive('dispatchPending')->once();
        $sync->shouldReceive('profile')->with('test', true)->andReturn($profile);
        $sync->shouldNotReceive('enqueue');
        $this->artisan('pos:sync-sales', ['--scheduled' => true])->assertExitCode(0);
        $this->assertSame(1, DB::table('pos_sync_cursors')->count());
        $this->assertSame(0, DB::table('import_logs')->count());
    }

    public function test_preview_does_not_write_and_retries_do_not_deduct_twice(): void
    {
        $sync = new PosSalesSync;
        $this->assertSame('ready', $sync->process($this->packet(), $this->profile())['status']);
        $this->assertSame(0, DB::table('store_transactions')->count());
        $this->assertSame('imported', $sync->process($this->packet(), $this->profile(), 123)['status']);
        $this->assertSame('unchanged', $sync->process($this->packet(), $this->profile(), 124)['status']);
        $this->assertSame(1, DB::table('store_transactions')->count());
        $this->assertSame(1, DB::table('pos_sync_receipts')->count());
        $this->assertSame(1.0, (float) DB::table('product_inventory_stock_managers')->where('action', 'out')->sum('quantity'));
        $this->assertSame('10', (string) DB::table('store_transaction_items')->value('product_id'));
        $this->assertSame('1', (string) DB::table('store_transaction_items')->value('entity_id'));
    }

    public function test_changed_or_cancelled_imported_receipt_is_held_for_review(): void
    {
        $sync = new PosSalesSync;
        $packet = $this->packet();
        $sync->process($packet, $this->profile(), 123);
        $packet['hash'] = hash('sha256', 'changed');
        $this->assertSame('review', $sync->process($packet, $this->profile(), 124)['status']);
        $packet['error'] = 'Receipt has cancellation records';
        $this->assertStringContainsString('Previously imported', $sync->process($packet, $this->profile(), 124)['reason']);
        $this->assertSame(1, DB::table('product_inventory_stock_managers')->where('action', 'out')->count());
    }

    public function test_tracking_failure_rolls_back_sale_items_and_stock(): void
    {
        DB::unprepared("CREATE TRIGGER reject_tracking BEFORE INSERT ON pos_sync_receipts BEGIN SELECT RAISE(ABORT, 'tracking unavailable'); END");
        try {
            (new PosSalesSync)->process($this->packet(), $this->profile(), 123);
            $this->fail('Expected tracking insert failure');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('tracking unavailable', $e->getMessage());
        }
        $this->assertSame(0, DB::table('store_transactions')->count());
        $this->assertSame(0, DB::table('store_transaction_items')->count());
        $this->assertSame(0, DB::table('product_inventory_stock_managers')->where('action', 'out')->count());
    }

    public function test_excel_after_pos_and_pos_after_excel_cannot_duplicate_receipt(): void
    {
        (new PosSalesSync)->process($this->packet(), $this->profile(), 123);
        $import = new StoreTransactionImport;
        $import->collection(collect([
            ['Product ID', 'Branch', 'Receipt No', 'TM#', 'Date', 'Qty', 'Base Qty', 'Price'],
            ['COFFEE', 'TEST', '42', '0041', '2026-09-19', 2, 2, 100],
        ]));
        $this->assertSame(0, $import->getCreatedCount());
        $packet = $this->packet();
        $packet['key'] = hash('sha256', 'another-source');
        $this->assertSame('review', (new PosSalesSync)->process($packet, $this->profile(), 124)['status']);
        $this->assertSame(1, DB::table('store_transactions')->count());
    }

    public function test_wrong_entity_cannot_post_and_low_stock_still_records_consumption(): void
    {
        app(EntityContext::class)->set(2);
        try {
            (new PosSalesSync)->process($this->packet(), $this->profile(), 123);
            $this->fail('Expected entity scope to reject branch');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame(0, DB::table('store_transactions')->count());
        }
        app(EntityContext::class)->set(1);
        DB::table('product_inventory_stock_managers')->update(['quantity' => '0.25']);
        $result = (new PosSalesSync)->process($this->packet(), $this->profile(), 123);
        $this->assertSame('imported', $result['status']);
        $this->assertSame(1, DB::table('pos_sync_receipts')->count());
        $this->assertSame(1.0, (float) DB::table('product_inventory_stock_managers')->where('action', 'out')->sum('quantity'));
    }

    public function test_conflicting_duplicate_lines_and_incomplete_receipts_are_rejected(): void
    {
        $mapper = new PosReceiptMapper;
        $line = $this->line();
        $this->assertCount(1, $mapper->map($this->sale(), [$line, $line], $this->profile()));
        $changed = array_replace($line, ['fqty' => '1', 'ftotal_line' => '90']);
        try {
            $mapper->map($this->sale(), [$line, $changed], $this->profile());
            $this->fail('Expected conflicting duplicate failure');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Conflicting', $e->getMessage());
        }
        $this->expectExceptionMessage('does not match its header');
        $mapper->map($this->sale(), [$changed], $this->profile());
    }

    public function test_source_reads_full_receipts_and_reports_cancellations(): void
    {
        foreach (['pos_sale' => $this->sale(), 'pos_sale_product' => $this->line(),
            'pos_sale_cancel' => ['fcompanyid' => '', 'fpubid' => '', 'frecno' => '']] as $name => $sample) {
            Schema::create($name, function (Blueprint $table) use ($sample) {
                foreach (array_keys($sample) as $column) {
                    $table->string($column);
                }
                $table->dateTime('_sync_timestamp');
            });
            if ($name !== 'pos_sale_cancel') {
                DB::table($name)->insert($sample + ['_sync_timestamp' => '2020-01-01 00:00:00']);
            }
        }
        $source = new PosSalesSource;
        $cutoverProfile = $this->profile() + ['start_date' => '2026-09-20'];
        $this->assertFalse($source->keys($cutoverProfile, '2026-09-19', '2026-09-19')->exists());
        $packets = iterator_to_array($source->receipts($this->profile(), '2026-09-19', '2026-09-19'));
        $this->assertCount(1, $packets);
        $this->assertNull($packets[0]['error']);
        $this->assertTrue($source->keys($this->profile(), '2030-01-01', '2030-01-02', [
            'since' => '2019-12-31 00:00:00', 'until' => '2020-01-02 00:00:00',
        ])->exists()); // Incremental discovery includes changes to old sales.
        DB::table('pos_sale')->update(['ftermid' => '0099']);
        $moved = iterator_to_array($source->receipts($this->profile(), '2026-09-19', '2026-09-19'));
        $this->assertSame('0099', $moved[0]['rows'][0]['tm']); // Paid on another terminal.
        DB::table('pos_sale_cancel')->insert(['fcompanyid' => 'COMPANY', 'fpubid' => 'PUB', 'frecno' => '1', '_sync_timestamp' => '2020-01-01']);
        $packets = iterator_to_array($source->receipts($this->profile(), '2026-09-19', '2026-09-19'));
        $this->assertNull($packets[0]['error']); // Historical cancellation rows do not void an active sale.
        DB::table('pos_sale_product')->update(['fstatus_flag' => 'W']);
        $packets = iterator_to_array($source->receipts($this->profile(), '2026-09-19', '2026-09-19'));
        $this->assertStringContainsString('no active sold lines', $packets[0]['error']);
        $future = ['since' => '2040-01-01 00:00:00', 'until' => '2040-01-02 00:00:00'];
        $this->assertFalse($source->keys($this->profile(), '2026-09-19', '2026-09-19', $future)->exists());
        DB::table('pos_sync_exceptions')->insert(['source_key' => hash('sha256', 'retry'), 'entity_id' => 1,
            'store_branch_id' => 1, 'source_identity' => json_encode(['COMPANY','TEST','PUB','1']),
            'reason' => 'Master data needs repair', 'retry_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue($source->keys($this->profile(), '2026-09-19', '2026-09-19', $future)->exists());
        DB::table('pos_sync_exceptions')->update(['resolved_at' => now()]);
        $this->assertFalse($source->keys($this->profile(), '2026-09-19', '2026-09-19', $future)->exists());
        DB::table('store_orders')->delete();
        $this->assertFalse($source->keys($this->profile(), '2026-09-19', '2026-09-19')->exists());

    }

    public function test_work_queue_job_reports_success_and_replay_is_harmless(): void
    {
        Schema::create('users', function (Blueprint $table) { $table->id(); });
        DB::table('users')->insert(['id' => 1]);
        (require base_path('database/migrations/2026_03_16_000001_create_import_logs_table.php'))->up();
        Schema::table('import_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('entity_id');
            $table->string('source_file_path')->nullable();
            $table->dateTime('processing_started_at')->nullable();
            $table->dateTime('last_heartbeat_at')->nullable();
            $table->dateTime('failed_at')->nullable();
        });
        config(['filesystems.default' => 'local']);
        \Illuminate\Support\Facades\Storage::fake('local');
        $profile = $this->profile() + ['confirmed' => true, 'user_id' => 1];
        config(['pos_sync.profiles.test' => $profile]);
        $path = 'imports/pos-sales/test.json';
        \Illuminate\Support\Facades\Storage::put($path, json_encode([
            'name' => 'test', 'profile' => $profile, 'from' => '2026-09-19', 'to' => '2026-09-19',
        ]));
        $log = \App\Models\ImportLog::create(['entity_id' => 1, 'user_id' => 1, 'type' => 'pos_sales',
            'original_filename' => 'POS test', 'source_file_path' => $path, 'status' => 'pending']);
        $packet = $this->packet();
        $this->mock(PosSalesSource::class)->shouldReceive('receipts')->once()->andReturn((function () use ($packet) { yield $packet; })());
        $this->mock(\App\Services\ImportQueueService::class)->shouldReceive('dispatchNextPending')->once()->with($log->id)->andReturnNull();
        $job = new \App\Jobs\PosSalesSyncJob($path, $log->id);
        $job->handle();
        $job->handle();
        $log->refresh();
        $this->assertSame('completed', $log->status);
        $this->assertSame(1, $log->processed_count);
        $this->assertSame(0, $log->skipped_count);
        \Illuminate\Support\Facades\Storage::assertExists($log->skipped_file_path);
        \Illuminate\Support\Facades\Storage::assertExists($path);
        $this->assertSame(1, DB::table('store_transactions')->count());
    }

    public function test_no_receipt_and_fractional_quantities_are_not_silently_imported(): void
    {
        $mapper = new PosReceiptMapper;
        try {
            $mapper->map(array_replace($this->sale(), ['ftrx_no' => '0']), [$this->line()], $this->profile());
            $this->fail('Expected no-receipt exclusion');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, DB::table('store_transactions')->count());
        }
        $this->expectExceptionMessage('fractional quantity');
        $mapper->map($this->sale(), [array_replace($this->line(), ['fqty' => '0.5'])], $this->profile());
    }

    public function test_report_amounts_take_out_and_posted_time_follow_pos_report_values(): void
    {
        $mapper = new PosReceiptMapper;
        $sale = array_replace($this->sale(), ['fsubtotal' => '1650', 'fgross' => '1414.29', 'fservice_charge' => '0']);
        $first = array_replace($this->line(), ['fqty' => '1', 'funitprice' => '625', 'ftotal_line' => '625',
            'fvar' => '-14.2864', 'fdeliver_flag' => '1']);
        $second = array_replace($this->line(), ['fseqno' => '2', 'fqty' => '1', 'funitprice' => '1025',
            'ftotal_line' => '1025', 'fvar' => '0', 'fdeliver_flag' => '0']);
        $rows = $mapper->map($sale, [$first, $second], $this->profile());
        $this->assertSame(535.71, $rows[0]['net_total']);
        $this->assertSame('1244', $rows[0]['posted']);
        $this->assertSame('Y', $rows[0]['take_out']);
        $this->assertSame('', $rows[1]['take_out']);
        $this->assertSame(878.57, $rows[1]['net_total']);
        $free = array_replace($this->line(), ['fqty' => '1', 'funitprice' => '295', 'ftotal_line' => '0']);
        $rows = $mapper->map(array_replace($this->sale(), ['fsubtotal' => '0', 'fgross' => '0']), [$free], $this->profile());
        $this->assertSame(295.0, $rows[0]['discount']);
    }

    public function test_latest_source_revision_supersedes_open_bill_snapshot(): void
    {
        $open = $this->sale() + ['fupdated_date' => '20260919120000', '_sync_timestamp' => '2026-09-19 12:05:00'];
        $open['ftrx_no'] = '0';
        $closed = $this->sale() + ['fupdated_date' => '20260919124400', '_sync_timestamp' => '2026-09-19 12:45:00'];
        $mapper = new PosReceiptMapper;
        $this->assertSame('42', $mapper->uniqueRows([$closed, $open], 'frecno')[0]['ftrx_no']);
        $this->assertSame('42', $mapper->uniqueRows([$open, $closed], 'frecno')[0]['ftrx_no']);
    }

    public function test_same_receipt_number_on_another_terminal_is_a_distinct_sale(): void
    {
        $sync = new PosSalesSync;
        $sync->process($this->packet(), $this->profile(), 123);
        $packet = $this->packet();
        $packet['key'] = hash('sha256', 'other-terminal');
        $packet['rows'][0]['tm'] = '0042';
        $packet['hash'] = hash('sha256', json_encode($packet['rows']));
        $this->assertSame('imported', $sync->process($packet, $this->profile(), 123)['status']);
        $this->assertSame(2, DB::table('store_transactions')->count());
    }

    public function test_finalized_sale_consumes_stock_even_when_receiving_is_late(): void
    {
        DB::table('product_inventory_stock_managers')->update(['quantity' => '0']);
        $result = (new PosSalesSync)->process($this->packet(), $this->profile(), 123);
        $this->assertSame('imported', $result['status']);
        $this->assertSame(1.0, (float) DB::table('product_inventory_stock_managers')->where('action', 'out')->sum('quantity'));
    }

    public function test_incremental_cursor_is_monotonic_and_pos_queue_does_not_block_excel(): void
    {
        (require base_path('database/migrations/2026_09_20_000002_create_pos_sync_cursors_table.php'))->up();
        $sync = new PosSalesSync;
        $sync->advanceCursor($this->profile(), '2026-09-20 12:00:00');
        $sync->advanceCursor($this->profile(), '2026-09-20 11:00:00');
        $this->assertSame('2026-09-20 12:00:00', DB::table('pos_sync_cursors')->value('synced_until'));
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id(); $table->integer('entity_id'); $table->string('type'); $table->string('status');
        });
        Schema::create('jobs', function (Blueprint $table) {
            $table->id(); $table->string('queue'); $table->text('payload');
            $table->integer('attempts')->default(0); $table->integer('reserved_at')->nullable();
            $table->integer('available_at')->default(0); $table->integer('created_at')->default(0);
        });
        DB::table('import_logs')->insert(['id' => 1, 'entity_id' => 1, 'type' => 'pos_sales', 'status' => 'processing']);
        $job = new \App\Jobs\PosSalesSyncJob('request.json', 1);
        DB::table('jobs')->insert(['queue' => 'pos-sales', 'payload' => json_encode(['data' => ['command' => serialize($job)]])]);
        $queue = new \App\Services\ImportQueueService;
        $this->assertSame('pos-sales', $job->queue);
        $this->assertNotNull($queue->queueJobForImportLogId(1));
        $this->assertFalse($queue->hasActiveImport());
        DB::table('import_logs')->insert(['id' => 2, 'entity_id' => 1, 'type' => 'store_transaction', 'status' => 'processing']);
        $this->assertTrue($queue->hasActiveImport());
    }
}
