<?php

namespace Tests\Unit\Models;

use Tests\TestCase;
use App\Models\ApprovalMatrix;
use App\Models\ApprovalMatrixRule;
use App\Models\ApprovalMatrixApprover;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ApprovalMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_create_approval_matrix()
    {
        $matrix = ApprovalMatrix::factory()->create([
            'matrix_name' => 'Test Matrix',
            'module_name' => 'store_orders',
            'entity_type' => 'regular',
            'approval_levels' => 2,
            'basis_column' => 'total_amount',
            'basis_operator' => 'greater_than',
            'basis_value' => json_encode([1000]),
            'created_by' => $this->user->id,
        ]);

        $this->assertInstanceOf(ApprovalMatrix::class, $matrix);
        $this->assertEquals('Test Matrix', $matrix->matrix_name);
        $this->assertEquals('store_orders', $matrix->module_name);
        $this->assertEquals('regular', $matrix->entity_type);
        $this->assertEquals(2, $matrix->approval_levels);
        $this->assertEquals('total_amount', $matrix->basis_column);
        $this->assertEquals('greater_than', $matrix->basis_operator);
        $this->assertJson($matrix->basis_value);
    }

    /** @test */
    public function it_has_relationships()
    {
        $matrix = ApprovalMatrix::factory()->create();

        // Create related records
        $rule = ApprovalMatrixRule::factory()->create(['approval_matrix_id' => $matrix->id]);
        $approver = ApprovalMatrixApprover::factory()->create(['approval_matrix_id' => $matrix->id]);

        $this->assertInstanceOf(ApprovalMatrixRule::class, $matrix->rules->first());
        $this->assertInstanceOf(ApprovalMatrixApprover::class, $matrix->approvers->first());
        $this->assertInstanceOf(User::class, $matrix->creator);
    }

    /** @test */
    public function it_can_scope_by_active_status()
    {
        $activeMatrix = ApprovalMatrix::factory()->create(['is_active' => true]);
        $inactiveMatrix = ApprovalMatrix::factory()->create(['is_active' => false]);

        $activeMatrices = ApprovalMatrix::active()->get();
        $inactiveMatrices = ApprovalMatrix::inactive()->get();

        $this->assertCount(1, $activeMatrices);
        $this->assertCount(1, $inactiveMatrices);
        $this->assertEquals($activeMatrix->id, $activeMatrices->first()->id);
        $this->assertEquals($inactiveMatrix->id, $inactiveMatrices->first()->id);
    }

    /** @test */
    public function it_can_scope_by_module()
    {
        $storeOrderMatrix = ApprovalMatrix::factory()->create(['module_name' => 'store_orders']);
        $wastageMatrix = ApprovalMatrix::factory()->create(['module_name' => 'wastages']);

        $storeOrderMatrices = ApprovalMatrix::forModule('store_orders')->get();
        $wastageMatrices = ApprovalMatrix::forModule('wastages')->get();

        $this->assertCount(1, $storeOrderMatrices);
        $this->assertCount(1, $wastageMatrices);
        $this->assertEquals($storeOrderMatrix->id, $storeOrderMatrices->first()->id);
        $this->assertEquals($wastageMatrix->id, $wastageMatrices->first()->id);
    }

    /** @test */
    public function it_can_scope_by_entity_type()
    {
        $regularMatrix = ApprovalMatrix::factory()->create(['entity_type' => 'regular']);
        $massOrderMatrix = ApprovalMatrix::factory()->create(['entity_type' => 'mass_order']);

        $regularMatrices = ApprovalMatrix::forEntityType('regular')->get();
        $massOrderMatrices = ApprovalMatrix::forEntityType('mass_order')->get();

        $this->assertCount(1, $regularMatrices);
        $this->assertCount(1, $massOrderMatrices);
        $this->assertEquals($regularMatrix->id, $regularMatrices->first()->id);
        $this->assertEquals($massOrderMatrix->id, $massOrderMatrices->first()->id);
    }

    /** @test */
    public function it_can_check_if_is_currently_active()
    {
        $currentMatrix = ApprovalMatrix::factory()->create([
            'effective_date' => now()->subDays(1),
            'expiry_date' => now()->addDays(1),
            'is_active' => true,
        ]);

        $expiredMatrix = ApprovalMatrix::factory()->create([
            'effective_date' => now()->subDays(10),
            'expiry_date' => now()->subDays(1),
            'is_active' => true,
        ]);

        $futureMatrix = ApprovalMatrix::factory()->create([
            'effective_date' => now()->addDays(1),
            'expiry_date' => now()->addDays(10),
            'is_active' => true,
        ]);

        $inactiveMatrix = ApprovalMatrix::factory()->create([
            'is_active' => false,
        ]);

        $this->assertTrue($currentMatrix->isCurrentlyActive());
        $this->assertFalse($expiredMatrix->isCurrentlyActive());
        $this->assertFalse($futureMatrix->isCurrentlyActive());
        $this->assertFalse($inactiveMatrix->isCurrentlyActive());
    }

    /** @test */
    public function it_can_get_approvers_by_level()
    {
        $matrix = ApprovalMatrix::factory()->create(['approval_levels' => 3]);

        $level1Approver = ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $matrix->id,
            'approval_level' => 1,
        ]);

        $level2Approver = ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $matrix->id,
            'approval_level' => 2,
        ]);

        $level3Approver = ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $matrix->id,
            'approval_level' => 3,
        ]);

        $level1Approvers = $matrix->getApproversByLevel(1);
        $level2Approvers = $matrix->getApproversByLevel(2);
        $level3Approvers = $matrix->getApproversByLevel(3);

        $this->assertCount(1, $level1Approvers);
        $this->assertCount(1, $level2Approvers);
        $this->assertCount(1, $level3Approvers);
        $this->assertEquals($level1Approver->id, $level1Approvers->first()->id);
        $this->assertEquals($level2Approver->id, $level2Approvers->first()->id);
        $this->assertEquals($level3Approver->id, $level3Approvers->first()->id);
    }

    /** @test */
    public function it_validates_basis_value_format()
    {
        // Test valid formats
        $validCases = [
            'equals' => '["value"]',
            'in' => '["value1", "value2"]',
            'greater_than' => '1000',
            'between' => '[1000, 5000]',
        ];

        foreach ($validCases as $operator => $value) {
            $matrix = ApprovalMatrix::factory()->create([
                'basis_operator' => $operator,
                'basis_value' => $value,
            ]);
            $this->assertInstanceOf(ApprovalMatrix::class, $matrix);
        }
    }

    /** @test */
    public function it_can_cast_json_attributes()
    {
        $arrayValue = ['value1', 'value2', 'value3'];
        $matrix = ApprovalMatrix::factory()->create([
            'basis_value' => $arrayValue,
        ]);

        $this->assertIsArray($matrix->basis_value);
        $this->assertEquals($arrayValue, $matrix->basis_value);
    }
}