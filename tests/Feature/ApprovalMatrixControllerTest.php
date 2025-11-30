<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\ApprovalMatrix;
use App\Models\ApprovalMatrixRule;
use App\Models\ApprovalMatrixApprover;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Str;

class ApprovalMatrixControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->regularUser = User::factory()->create();
    }

    /** @test */
    public function it_can_list_approval_matrices()
    {
        Sanctum::actingAs($this->admin);

        ApprovalMatrix::factory()->count(3)->create();

        $response = $this->getJson('/api/approval-matrices');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'matrix_name',
                        'module_name',
                        'entity_type',
                        'approval_levels',
                        'is_active',
                        'created_at',
                        'updated_at',
                    ]
                ],
                'links',
                'meta'
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    /** @test */
    public function it_can_filter_approval_matrices_by_module()
    {
        Sanctum::actingAs($this->admin);

        ApprovalMatrix::factory()->create(['module_name' => 'store_orders']);
        ApprovalMatrix::factory()->create(['module_name' => 'wastages']);
        ApprovalMatrix::factory()->create(['module_name' => 'interco_transfers']);

        $response = $this->getJson('/api/approval-matrices?module=store_orders');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('store_orders', $response->json('data.0.module_name'));
    }

    /** @test */
    public function it_can_show_approval_matrix()
    {
        Sanctum::actingAs($this->admin);

        $matrix = ApprovalMatrix::factory()->create();
        $rule = ApprovalMatrixRule::factory()->create(['approval_matrix_id' => $matrix->id]);
        $approver = ApprovalMatrixApprover::factory()->create(['approval_matrix_id' => $matrix->id]);

        $response = $this->getJson("/api/approval-matrices/{$matrix->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'matrix_name',
                    'module_name',
                    'entity_type',
                    'approval_levels',
                    'basis_column',
                    'basis_operator',
                    'basis_value',
                    'minimum_amount',
                    'maximum_amount',
                    'is_active',
                    'effective_date',
                    'expiry_date',
                    'priority',
                    'description',
                    'rules' => [
                        '*' => [
                            'id',
                            'condition_group',
                            'condition_logic',
                            'condition_column',
                            'condition_operator',
                            'condition_value',
                            'is_active',
                        ]
                    ],
                    'approvers' => [
                        '*' => [
                            'id',
                            'user_id',
                            'approval_level',
                            'is_primary',
                            'is_backup',
                            'can_delegate',
                            'approval_limit_amount',
                            'approval_limit_percentage',
                            'approval_deadline_hours',
                            'business_hours_only',
                            'is_active',
                        ]
                    ],
                    'creator',
                    'updater',
                    'created_at',
                    'updated_at',
                ]
            ]);
    }

    /** @test */
    public function it_returns_404_for_nonexistent_approval_matrix()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/approval-matrices/999999');

        $response->assertStatus(404);
    }

    /** @test */
    public function it_can_create_approval_matrix()
    {
        Sanctum::actingAs($this->admin);

        $data = [
            'matrix_name' => 'Test Matrix',
            'module_name' => 'store_orders',
            'entity_type' => 'regular',
            'approval_levels' => 2,
            'basis_column' => 'total_amount',
            'basis_operator' => 'greater_than',
            'basis_value' => [5000],
            'minimum_amount' => 1000,
            'maximum_amount' => 50000,
            'is_active' => true,
            'effective_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'priority' => 5,
            'description' => 'Test matrix description',
            'approvers' => [
                [
                    'user_id' => User::factory()->create()->id,
                    'approval_level' => 1,
                    'is_primary' => true,
                    'approval_deadline_hours' => 24,
                ],
                [
                    'user_id' => User::factory()->create()->id,
                    'approval_level' => 2,
                    'is_primary' => true,
                    'approval_deadline_hours' => 48,
                ]
            ],
            'rules' => [
                [
                    'condition_column' => 'store_branch.region',
                    'condition_operator' => 'in',
                    'condition_value' => ['north', 'central'],
                ]
            ]
        ];

        $response = $this->postJson('/api/approval-matrices', $data);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'matrix_name',
                    'module_name',
                    'entity_type',
                    'approval_levels',
                    'is_active',
                    'created_at',
                ]
            ]);

        $this->assertDatabaseHas('approval_matrices', [
            'matrix_name' => 'Test Matrix',
            'module_name' => 'store_orders',
            'approval_levels' => 2,
        ]);

        $matrix = ApprovalMatrix::first();
        $this->assertCount(2, $matrix->approvers);
        $this->assertCount(1, $matrix->rules);
    }

    /** @test */
    public function it_validates_required_fields_when_creating_approval_matrix()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/approval-matrices', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'matrix_name',
                'module_name',
                'entity_type',
                'approval_levels',
                'basis_column',
                'basis_operator',
            ]);
    }

    /** @test */
    public function it_validates_module_name_enum()
    {
        Sanctum::actingAs($this->admin);

        $data = ApprovalMatrix::factory()->raw([
            'module_name' => 'invalid_module',
        ]);

        $response = $this->postJson('/api/approval-matrices', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['module_name']);
    }

    /** @test */
    public function it_validates_approval_levels_minimum()
    {
        Sanctum::actingAs($this->admin);

        $data = ApprovalMatrix::factory()->raw([
            'approval_levels' => 0,
        ]);

        $response = $this->postJson('/api/approval-matrices', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['approval_levels']);
    }

    /** @test */
    public function it_validates_unique_matrix_name_per_module()
    {
        Sanctum::actingAs($this->admin);

        ApprovalMatrix::factory()->create([
            'matrix_name' => 'Duplicate Test',
            'module_name' => 'store_orders',
        ]);

        $data = ApprovalMatrix::factory()->raw([
            'matrix_name' => 'Duplicate Test',
            'module_name' => 'store_orders',
        ]);

        $response = $this->postJson('/api/approval-matrices', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['matrix_name']);
    }

    /** @test */
    public function it_can_update_approval_matrix()
    {
        Sanctum::actingAs($this->admin);

        $matrix = ApprovalMatrix::factory()->create();

        $data = [
            'matrix_name' => 'Updated Matrix Name',
            'description' => 'Updated description',
            'is_active' => false,
        ];

        $response = $this->putJson("/api/approval-matrices/{$matrix->id}", $data);

        $response->assertStatus(200)
            ->assertJsonPath('data.matrix_name', 'Updated Matrix Name')
            ->assertJsonPath('data.description', 'Updated description')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('approval_matrices', [
            'id' => $matrix->id,
            'matrix_name' => 'Updated Matrix Name',
            'description' => 'Updated description',
            'is_active' => false,
        ]);
    }

    /** @test */
    public function it_can_delete_approval_matrix()
    {
        Sanctum::actingAs($this->admin);

        $matrix = ApprovalMatrix::factory()->create();

        $response = $this->deleteJson("/api/approval-matrices/{$matrix->id}");

        $response->assertStatus(204);

        $this->assertSoftDeleted('approval_matrices', [
            'id' => $matrix->id,
        ]);
    }

    /** @test */
    public function it_cannot_delete_matrix_with_active_workflows()
    {
        Sanctum::actingAs($this->admin);

        $matrix = ApprovalMatrix::factory()->create();

        // Create a workflow for this matrix
        \App\Models\EntityApprovalWorkflow::factory()->create([
            'approval_matrix_id' => $matrix->id,
            'current_status' => 'pending',
        ]);

        $response = $this->deleteJson("/api/approval-matrices/{$matrix->id}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['active_workflows']);
    }

    /** @test */
    public function it_can_duplicate_approval_matrix()
    {
        Sanctum::actingAs($this->admin);

        $matrix = ApprovalMatrix::factory()->create();
        ApprovalMatrixRule::factory()->count(2)->create(['approval_matrix_id' => $matrix->id]);
        ApprovalMatrixApprover::factory()->count(2)->create(['approval_matrix_id' => $matrix->id]);

        $data = [
            'matrix_name' => 'Duplicate Matrix Test',
            'is_active' => false,
        ];

        $response = $this->postJson("/api/approval-matrices/{$matrix->id}/duplicate", $data);

        $response->assertStatus(201);

        $originalMatrix = ApprovalMatrix::find($matrix->id);
        $duplicatedMatrix = ApprovalMatrix::where('matrix_name', 'Duplicate Matrix Test')->first();

        $this->assertNotNull($duplicatedMatrix);
        $this->assertEquals($originalMatrix->module_name, $duplicatedMatrix->module_name);
        $this->assertEquals($originalMatrix->entity_type, $duplicatedMatrix->entity_type);
        $this->assertEquals($originalMatrix->approval_levels, $duplicatedMatrix->approval_levels);
        $this->assertFalse($duplicatedMatrix->is_active);

        // Check that rules and approvers were duplicated
        $this->assertCount(2, $duplicatedMatrix->rules);
        $this->assertCount(2, $duplicatedMatrix->approvers);
    }

    /** @test */
    public function it_can_toggle_approval_matrix_status()
    {
        Sanctum::actingAs($this->admin);

        $matrix = ApprovalMatrix::factory()->create(['is_active' => true]);

        $response = $this->patchJson("/api/approval-matrices/{$matrix->id}/toggle-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('approval_matrices', [
            'id' => $matrix->id,
            'is_active' => false,
        ]);

        // Toggle back to active
        $response = $this->patchJson("/api/approval-matrices/{$matrix->id}/toggle-status");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', true);
    }

    /** @test */
    public function it_can_get_approval_matrix_statistics()
    {
        Sanctum::actingAs($this->admin);

        ApprovalMatrix::factory()->count(3)->create(['is_active' => true]);
        ApprovalMatrix::factory()->count(2)->create(['is_active' => false]);

        $response = $this->getJson('/api/approval-matrices/statistics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_matrices',
                'active_matrices',
                'inactive_matrices',
                'matrices_by_module',
                'matrices_by_entity_type',
            ])
            ->assertJson([
                'total_matrices' => 5,
                'active_matrices' => 3,
                'inactive_matrices' => 2,
            ]);
    }

    /** @test */
    public function unauthorized_user_cannot_access_approval_matrices()
    {
        $response = $this->getJson('/api/approval-matrices');
        $response->assertStatus(401);

        Sanctum::actingAs($this->regularUser);
        $response = $this->getJson('/api/approval-matrices');
        $response->assertStatus(403);
    }

    /** @test */
    public function it_can_search_approval_matrices()
    {
        Sanctum::actingAs($this->admin);

        ApprovalMatrix::factory()->create(['matrix_name' => 'PUL-O Supplier Orders']);
        ApprovalMatrix::factory()->create(['matrix_name' => 'High Value Orders']);
        ApprovalMatrix::factory()->create(['matrix_name' => 'Regional Orders']);

        $response = $this->getJson('/api/approval-matrices?search=PUL-O');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertStringContainsString('PUL-O', $response->json('data.0.matrix_name'));
    }

    /** @test */
    public function it_can_filter_by_status()
    {
        Sanctum::actingAs($this->admin);

        ApprovalMatrix::factory()->count(3)->create(['is_active' => true]);
        ApprovalMatrix::factory()->count(2)->create(['is_active' => false]);

        $activeResponse = $this->getJson('/api/approval-matrices?status=active');
        $inactiveResponse = $this->getJson('/api/approval-matrices?status=inactive');

        $activeResponse->assertStatus(200);
        $this->assertCount(3, $activeResponse->json('data'));

        $inactiveResponse->assertStatus(200);
        $this->assertCount(2, $inactiveResponse->json('data'));

        foreach ($activeResponse->json('data') as $matrix) {
            $this->assertTrue($matrix['is_active']);
        }

        foreach ($inactiveResponse->json('data') as $matrix) {
            $this->assertFalse($matrix['is_active']);
        }
    }

    /** @test */
    public function it_validates_date_ranges()
    {
        Sanctum::actingAs($this->admin);

        $data = ApprovalMatrix::factory()->raw([
            'effective_date' => now()->addDays(10)->toDateString(),
            'expiry_date' => now()->subDays(10)->toDateString(),
        ]);

        $response = $this->postJson('/api/approval-matrices', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['expiry_date']);
    }
}