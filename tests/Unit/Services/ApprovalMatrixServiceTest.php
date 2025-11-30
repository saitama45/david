<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\ApprovalMatrixService;
use App\Models\ApprovalMatrix;
use App\Models\ApprovalMatrixRule;
use App\Models\ApprovalMatrixApprover;
use App\Models\User;
use App\Models\StoreOrder;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class ApprovalMatrixServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ApprovalMatrixService $service;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ApprovalMatrixService();
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_find_matching_matrix_for_simple_case()
    {
        // Create PUL-O supplier (ID: 4) matrix
        $matrix = ApprovalMatrix::factory()->create([
            'matrix_name' => 'PUL-O Supplier Orders',
            'module_name' => 'store_orders',
            'entity_type' => 'regular',
            'basis_column' => 'supplier_id',
            'basis_operator' => 'equals',
            'basis_value' => json_encode([4]),
            'approval_levels' => 1,
            'is_active' => true,
        ]);

        // Create approver for this matrix
        $approver = ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $matrix->id,
            'approval_level' => 1,
        ]);

        // Create test entity data
        $entity = new \stdClass();
        $entity->supplier_id = 4;
        $entity->total_amount = 5000;

        $result = $this->service->findMatchingMatrix('store_orders', 'regular', $entity);

        $this->assertNotNull($result);
        $this->assertEquals($matrix->id, $result->id);
    }

    /** @test */
    public function it_can_find_matching_matrix_with_complex_rules()
    {
        // Create matrix for high-value orders from Luzon region
        $matrix = ApprovalMatrix::factory()->create([
            'matrix_name' => 'High Value Luzon Orders',
            'module_name' => 'store_orders',
            'entity_type' => 'regular',
            'basis_column' => 'total_amount',
            'basis_operator' => 'greater_than',
            'basis_value' => json_encode([10000]),
            'approval_levels' => 2,
            'is_active' => true,
        ]);

        // Add region rule
        ApprovalMatrixRule::factory()->create([
            'approval_matrix_id' => $matrix->id,
            'condition_group' => 1,
            'condition_logic' => 'AND',
            'condition_column' => 'store_branch.region',
            'condition_operator' => 'in',
            'condition_value' => json_encode(['north', 'central']),
        ]);

        // Create test entity data
        $entity = new \stdClass();
        $entity->total_amount = 15000;
        $entity->store_branch = (object) ['region' => 'north'];

        $result = $this->service->findMatchingMatrix('store_orders', 'regular', $entity);

        $this->assertNotNull($result);
        $this->assertEquals($matrix->id, $result->id);
    }

    /** @test */
    public function it_returns_null_when_no_matrix_matches()
    {
        // Create matrix for PUL-O supplier
        ApprovalMatrix::factory()->create([
            'basis_column' => 'supplier_id',
            'basis_operator' => 'equals',
            'basis_value' => json_encode([4]),
            'approval_levels' => 1,
        ]);

        // Create test entity with different supplier
        $entity = new \stdClass();
        $entity->supplier_id = 5;

        $result = $this->service->findMatchingMatrix('store_orders', 'regular', $entity);

        $this->assertNull($result);
    }

    /** @test */
    public function it_respects_priority_order()
    {
        // Create lower priority matrix
        $lowPriorityMatrix = ApprovalMatrix::factory()->create([
            'matrix_name' => 'General Orders',
            'priority' => 1,
            'basis_column' => 'total_amount',
            'basis_operator' => 'greater_than',
            'basis_value' => json_encode([1000]),
            'approval_levels' => 1,
        ]);

        // Create higher priority matrix (specific supplier)
        $highPriorityMatrix = ApprovalMatrix::factory()->create([
            'matrix_name' => 'PUL-O Supplier Orders',
            'priority' => 10,
            'basis_column' => 'supplier_id',
            'basis_operator' => 'equals',
            'basis_value' => json_encode([4]),
            'approval_levels' => 1,
        ]);

        // Create test entity that matches both
        $entity = new \stdClass();
        $entity->supplier_id = 4;
        $entity->total_amount = 5000;

        $result = $this->service->findMatchingMatrix('store_orders', 'regular', $entity);

        // Should return the higher priority matrix
        $this->assertEquals($highPriorityMatrix->id, $result->id);
    }

    /** @test */
    public function it_only_returns_active_matrices()
    {
        // Create inactive matrix
        ApprovalMatrix::factory()->create([
            'basis_column' => 'supplier_id',
            'basis_operator' => 'equals',
            'basis_value' => json_encode([4]),
            'is_active' => false,
        ]);

        // Create test entity
        $entity = new \stdClass();
        $entity->supplier_id = 4;

        $result = $this->service->findMatchingMatrix('store_orders', 'regular', $entity);

        $this->assertNull($result);
    }

    /** @test */
    public function it_respects_effective_and_expiry_dates()
    {
        // Create expired matrix
        ApprovalMatrix::factory()->create([
            'basis_column' => 'supplier_id',
            'basis_operator' => 'equals',
            'basis_value' => json_encode([4]),
            'effective_date' => now()->subDays(10),
            'expiry_date' => now()->subDays(1),
        ]);

        // Create future matrix
        ApprovalMatrix::factory()->create([
            'basis_column' => 'supplier_id',
            'basis_operator' => 'equals',
            'basis_value' => json_encode([4]),
            'effective_date' => now()->addDays(1),
            'expiry_date' => now()->addDays(10),
        ]);

        // Create current matrix
        $currentMatrix = ApprovalMatrix::factory()->create([
            'basis_column' => 'supplier_id',
            'basis_operator' => 'equals',
            'basis_value' => json_encode([4]),
            'effective_date' => now()->subDays(1),
            'expiry_date' => now()->addDays(1),
        ]);

        // Create test entity
        $entity = new \stdClass();
        $entity->supplier_id = 4;

        $result = $this->service->findMatchingMatrix('store_orders', 'regular', $entity);

        $this->assertEquals($currentMatrix->id, $result->id);
    }

    /** @test */
    public function it_evaluates_different_operators_correctly()
    {
        $testCases = [
            ['operator' => 'equals', 'value' => [4], 'entity_value' => 4, 'should_match' => true],
            ['operator' => 'equals', 'value' => [5], 'entity_value' => 4, 'should_match' => false],
            ['operator' => 'in', 'value' => [4, 5, 6], 'entity_value' => 5, 'should_match' => true],
            ['operator' => 'in', 'value' => [4, 5, 6], 'entity_value' => 7, 'should_match' => false],
            ['operator' => 'greater_than', 'value' => [1000], 'entity_value' => 1500, 'should_match' => true],
            ['operator' => 'greater_than', 'value' => [1000], 'entity_value' => 800, 'should_match' => false],
            ['operator' => 'less_than', 'value' => [5000], 'entity_value' => 3000, 'should_match' => true],
            ['operator' => 'less_than', 'value' => [5000], 'entity_value' => 6000, 'should_match' => false],
            ['operator' => 'between', 'value' => [1000, 5000], 'entity_value' => 3000, 'should_match' => true],
            ['operator' => 'between', 'value' => [1000, 5000], 'entity_value' => 6000, 'should_match' => false],
            ['operator' => 'not_equals', 'value' => [4], 'entity_value' => 5, 'should_match' => true],
            ['operator' => 'not_equals', 'value' => [4], 'entity_value' => 4, 'should_match' => false],
        ];

        foreach ($testCases as $case) {
            ApprovalMatrix::factory()->create([
                'basis_column' => 'test_column',
                'basis_operator' => $case['operator'],
                'basis_value' => json_encode($case['value']),
            ]);

            $entity = new \stdClass();
            $entity->test_column = $case['entity_value'];

            $result = $this->service->findMatchingMatrix('store_orders', 'regular', $entity);

            if ($case['should_match']) {
                $this->assertNotNull($result, "Should match for operator {$case['operator']} with value {$case['entity_value']}");
            } else {
                $this->assertNull($result, "Should not match for operator {$case['operator']} with value {$case['entity_value']}");
            }
        }
    }

    /** @test */
    public function it_can_validate_matrix_conditions_with_rules()
    {
        // Create matrix with multiple condition groups
        $matrix = ApprovalMatrix::factory()->create([
            'basis_column' => 'total_amount',
            'basis_operator' => 'greater_than',
            'basis_value' => json_encode([1000]),
        ]);

        // Add AND conditions - supplier must be in [4,5] AND region must be 'north'
        ApprovalMatrixRule::factory()->create([
            'approval_matrix_id' => $matrix->id,
            'condition_group' => 1,
            'condition_logic' => 'AND',
            'condition_column' => 'supplier_id',
            'condition_operator' => 'in',
            'condition_value' => json_encode([4, 5]),
        ]);

        ApprovalMatrixRule::factory()->create([
            'approval_matrix_id' => $matrix->id,
            'condition_group' => 1,
            'condition_logic' => 'AND',
            'condition_column' => 'store_branch.region',
            'condition_operator' => 'equals',
            'condition_value' => json_encode(['north']),
        ]);

        // Test case 1: Should match (all conditions met)
        $entity1 = new \stdClass();
        $entity1->total_amount = 2000;
        $entity1->supplier_id = 4;
        $entity1->store_branch = (object) ['region' => 'north'];

        $result1 = $this->service->findMatchingMatrix('store_orders', 'regular', $entity1);
        $this->assertNotNull($result1);

        // Test case 2: Should not match (region condition fails)
        $entity2 = new \stdClass();
        $entity2->total_amount = 2000;
        $entity2->supplier_id = 4;
        $entity2->store_branch = (object) ['region' => 'south'];

        $result2 = $this->service->findMatchingMatrix('store_orders', 'regular', $entity2);
        $this->assertNull($result2);

        // Test case 3: Should not match (supplier condition fails)
        $entity3 = new \stdClass();
        $entity3->total_amount = 2000;
        $entity3->supplier_id = 6;
        $entity3->store_branch = (object) ['region' => 'north'];

        $result3 = $this->service->findMatchingMatrix('store_orders', 'regular', $entity3);
        $this->assertNull($result3);
    }

    /** @test */
    public function it_can_get_approvers_for_matrix()
    {
        $matrix = ApprovalMatrix::factory()->create(['approval_levels' => 3]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        $approver1 = ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $matrix->id,
            'user_id' => $user1->id,
            'approval_level' => 1,
            'is_primary' => true,
        ]);

        $approver2 = ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $matrix->id,
            'user_id' => $user2->id,
            'approval_level' => 1,
            'is_backup' => true,
        ]);

        $approver3 = ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $matrix->id,
            'user_id' => $user3->id,
            'approval_level' => 2,
            'is_primary' => true,
        ]);

        $approvers = $this->service->getApproversForMatrix($matrix);

        $this->assertCount(3, $approvers);
        $this->assertTrue($approvers->contains($user1));
        $this->assertTrue($approvers->contains($user2));
        $this->assertTrue($approvers->contains($user3));
    }

    /** @test */
    public function it_can_get_approvers_by_level()
    {
        $matrix = ApprovalMatrix::factory()->create(['approval_levels' => 2]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $matrix->id,
            'user_id' => $user1->id,
            'approval_level' => 1,
        ]);

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $matrix->id,
            'user_id' => $user2->id,
            'approval_level' => 2,
        ]);

        $level1Approvers = $this->service->getApproversForMatrix($matrix, 1);
        $level2Approvers = $this->service->getApproversForMatrix($matrix, 2);

        $this->assertCount(1, $level1Approvers);
        $this->assertCount(1, $level2Approvers);
        $this->assertEquals($user1->id, $level1Approvers->first()->id);
        $this->assertEquals($user2->id, $level2Approvers->first()->id);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}