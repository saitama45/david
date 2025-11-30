<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\ApprovalMatrixService;
use App\Services\WorkflowService;
use App\Models\ApprovalMatrix;
use App\Models\ApprovalMatrixApprover;
use App\Models\EntityApprovalWorkflow;
use App\Models\ApprovalWorkflowStep;
use App\Models\User;
use App\Models\StoreOrder;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use App\Events\WorkflowInitiated;
use App\Events\ApprovalActionProcessed;
use App\Events\WorkflowCompleted;
use App\Mail\ApprovalNotification;
use App\Mail\ApprovalCompleted;

class ApprovalWorkflowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected ApprovalMatrixService $matrixService;
    protected WorkflowService $workflowService;
    protected User $requester;
    protected User $approver1;
    protected User $approver2;
    protected ApprovalMatrix $matrix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matrixService = new ApprovalMatrixService();
        $this->workflowService = new WorkflowService();

        $this->requester = User::factory()->create();
        $this->approver1 = User::factory()->create(['email' => 'approver1@example.com']);
        $this->approver2 = User::factory()->create(['email' => 'approver2@example.com']);

        Event::fake();
        Mail::fake();
    }

    /** @test */
    public function complete_pul_o_supplier_approval_workflow()
    {
        // Create PUL-O supplier matrix
        $this->matrix = ApprovalMatrix::factory()->create([
            'matrix_name' => 'PUL-O Supplier Orders',
            'module_name' => 'store_orders',
            'entity_type' => 'regular',
            'approval_levels' => 1,
            'basis_column' => 'supplier_id',
            'basis_operator' => 'equals',
            'basis_value' => json_encode([4]),
        ]);

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => $this->approver1->id,
            'approval_level' => 1,
            'is_primary' => true,
            'approval_deadline_hours' => 24,
        ]);

        // Create supplier
        $pulSupplier = Supplier::factory()->create([
            'id' => 4,
            'supplier_code' => 'PUL-O',
            'supplier_name' => 'PUL-O Supplies',
        ]);

        // Create store order from PUL-O supplier
        $storeOrder = StoreOrder::factory()->create([
            'supplier_id' => 4,
            'total_amount' => 5000,
            'created_by' => $this->requester->id,
        ]);

        // Initiate workflow
        $workflow = $this->workflowService->initiateWorkflow($storeOrder, 'store_orders', $this->requester);

        $this->assertNotNull($workflow);
        $this->assertEquals($this->matrix->id, $workflow->approval_matrix_id);
        $this->assertEquals(1, $workflow->total_approval_required);
        $this->assertEquals('pending', $workflow->current_status);

        // Check workflow steps were created
        $this->assertCount(1, $workflow->steps);
        $step = $workflow->steps->first();
        $this->assertEquals($this->approver1->id, $step->approver_user_id);
        $this->assertEquals('pending', $step->action);

        // Event should be fired
        Event::assertDispatched(WorkflowInitiated::class);

        // Approver logs in and views pending approvals
        Sanctum::actingAs($this->approver1);
        $response = $this->getJson('/api/pending-approvals');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));

        // Approver approves the order
        $approvalData = [
            'action' => 'approved',
            'action_reason' => 'Order reviewed and approved - all items within budget',
            'action_data' => [
                'notes' => 'Quick approval process',
                'verified_budget' => true,
            ],
        ];

        $response = $this->postJson("/api/approval-workflows/{$workflow->id}/process", $approvalData);
        $response->assertStatus(200);

        // Check workflow status
        $workflow->refresh();
        $this->assertEquals('approved', $workflow->current_status);
        $this->assertNotNull($workflow->completed_at);

        // Check step was updated
        $step->refresh();
        $this->assertEquals('approved', $step->action);
        $this->assertEquals($approvalData['action_reason'], $step->action_reason);
        $this->assertNotNull($step->action_taken_at);

        // Store order should be updated
        $storeOrder->refresh();
        $this->assertEquals($workflow->id, $storeOrder->approval_matrix_id);
        $this->assertEquals(1, $storeOrder->current_approval_level);
        $this->assertEquals(1, $storeOrder->total_approval_required);

        // Events should be fired
        Event::assertDispatched(ApprovalActionProcessed::class);
        Event::assertDispatched(WorkflowCompleted::class);

        // Emails should be sent
        Mail::assertSent(ApprovalNotification::class);
        Mail::assertSent(ApprovalCompleted::class);
    }

    /** @test */
    public function multi_level_amount_based_approval_workflow()
    {
        // Create amount-based matrix with 3 levels
        $this->matrix = ApprovalMatrix::factory()->create([
            'matrix_name' => 'Multi-Level Amount Approval',
            'module_name' => 'store_orders',
            'entity_type' => 'regular',
            'approval_levels' => 3,
            'basis_column' => 'total_amount',
            'basis_operator' => 'greater_than',
            'basis_value' => json_encode([10000]),
            'minimum_amount' => 10000,
        ]);

        // Create approvers for each level
        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => $this->approver1->id,
            'approval_level' => 1,
            'is_primary' => true,
            'approval_limit_amount' => 25000,
            'approval_deadline_hours' => 24,
        ]);

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => $this->approver2->id,
            'approval_level' => 2,
            'is_primary' => true,
            'approval_limit_amount' => 50000,
            'approval_deadline_hours' => 48,
        ]);

        $approver3 = User::factory()->create();
        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => $approver3->id,
            'approval_level' => 3,
            'is_primary' => true,
            'approval_limit_amount' => 100000,
            'approval_deadline_hours' => 72,
        ]);

        // Create high-value store order
        $storeOrder = StoreOrder::factory()->create([
            'total_amount' => 75000, // Requires 3-level approval
            'created_by' => $this->requester->id,
        ]);

        // Initiate workflow
        $workflow = $this->workflowService->initiateWorkflow($storeOrder, 'store_orders', $this->requester);

        $this->assertEquals(3, $workflow->total_approval_required);
        $this->assertCount(3, $workflow->steps);

        // Level 1 Approval
        Sanctum::actingAs($this->approver1);
        $this->postJson("/api/approval-workflows/{$workflow->id}/process", [
            'action' => 'approved',
            'action_reason' => 'Level 1 approval completed',
        ]);

        $workflow->refresh();
        $this->assertEquals('pending', $workflow->current_status);
        $this->assertEquals(1, $workflow->current_approval_level);

        // Level 2 Approval
        Sanctum::actingAs($this->approver2);
        $this->postJson("/api/approval-workflows/{$workflow->id}/process", [
            'action' => 'approved',
            'action_reason' => 'Level 2 approval completed',
        ]);

        $workflow->refresh();
        $this->assertEquals('pending', $workflow->current_status);
        $this->assertEquals(2, $workflow->current_approval_level);

        // Level 3 Approval (Final)
        Sanctum::actingAs($approver3);
        $this->postJson("/api/approval-workflows/{$workflow->id}/process", [
            'action' => 'approved',
            'action_reason' => 'Level 3 approval completed - workflow finished',
        ]);

        $workflow->refresh();
        $this->assertEquals('approved', $workflow->current_status);
        $this->assertEquals(3, $workflow->current_approval_level);
        $this->assertNotNull($workflow->completed_at);
    }

    /** @test */
    public function workflow_rejection_stops_process()
    {
        // Create matrix with 2 levels
        $this->matrix = ApprovalMatrix::factory()->create([
            'matrix_name' => 'Rejection Test Matrix',
            'module_name' => 'store_orders',
            'entity_type' => 'regular',
            'approval_levels' => 2,
        ]);

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => $this->approver1->id,
            'approval_level' => 1,
        ]);

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => $this->approver2->id,
            'approval_level' => 2,
        ]);

        $storeOrder = StoreOrder::factory()->create();
        $workflow = $this->workflowService->initiateWorkflow($storeOrder, 'store_orders', $this->requester);

        // Level 1 rejection
        Sanctum::actingAs($this->approver1);
        $rejectionReason = 'Budget exceeded - order amount beyond department limit';
        $this->postJson("/api/approval-workflows/{$workflow->id}/process", [
            'action' => 'rejected',
            'action_reason' => $rejectionReason,
        ]);

        $workflow->refresh();
        $this->assertEquals('rejected', $workflow->current_status);
        $this->assertEquals($rejectionReason, $workflow->rejection_reason);
        $this->assertNotNull($workflow->completed_at);

        // Level 2 approver should not be able to take action
        Sanctum::actingAs($this->approver2);
        $response = $this->postJson("/api/approval-workflows/{$workflow->id}/process", [
            'action' => 'approved',
            'action_reason' => 'Trying to approve after rejection',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['workflow_status']);
    }

    /** @test */
    public function workflow_cancellation_stops_process()
    {
        $this->matrix = ApprovalMatrix::factory()->create(['approval_levels' => 2]);

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => $this->approver1->id,
            'approval_level' => 1,
        ]);

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => $this->approver2->id,
            'approval_level' => 2,
        ]);

        $storeOrder = StoreOrder::factory()->create();
        $workflow = $this->workflowService->initiateWorkflow($storeOrder, 'store_orders', $this->requester);

        // Requester cancels the workflow
        Sanctum::actingAs($this->requester);
        $cancellationReason = 'Order cancelled - requirements changed';
        $response = $this->postJson("/api/approval-workflows/{$workflow->id}/cancel", [
            'cancellation_reason' => $cancellationReason,
        ]);

        $response->assertStatus(200);

        $workflow->refresh();
        $this->assertEquals('cancelled', $workflow->current_status);
        $this->assertEquals($cancellationReason, $workflow->cancellation_reason);
        $this->assertNotNull($workflow->completed_at);

        // Check that pending steps were marked as cancelled
        $cancelledSteps = $workflow->steps()->where('action', '!=', 'pending')->get();
        $this->assertGreaterThan(0, $cancelledSteps->count());

        // Event should be fired
        Event::assertDispatched(WorkflowCompleted::class, function ($event) {
            return $event->status === 'cancelled';
        });
    }

    /** @test */
    public function bulk_approval_operations()
    {
        // Create multiple pending workflows
        $workflows = [];
        for ($i = 1; $i <= 3; $i++) {
            $matrix = ApprovalMatrix::factory()->create(['approval_levels' => 1]);
            ApprovalMatrixApprover::factory()->create([
                'approval_matrix_id' => $matrix->id,
                'user_id' => $this->approver1->id,
                'approval_level' => 1,
            ]);

            $storeOrder = StoreOrder::factory()->create();
            $workflow = $this->workflowService->initiateWorkflow($storeOrder, 'store_orders', $this->requester);
            $workflows[] = $workflow;
        }

        // Perform bulk approval
        Sanctum::actingAs($this->approver1);
        $workflowIds = collect($workflows)->pluck('id')->toArray();

        $response = $this->postJson('/api/approval-workflows/bulk-process', [
            'workflow_ids' => $workflowIds,
            'action' => 'approved',
            'action_reason' => 'Bulk approval for routine orders',
        ]);

        $response->assertStatus(200);

        // Check all workflows were approved
        foreach ($workflowIds as $workflowId) {
            $workflow = EntityApprovalWorkflow::find($workflowId);
            $this->assertEquals('approved', $workflow->current_status);
            $this->assertNotNull($workflow->completed_at);
        }

        $response->assertJson([
            'message' => 'Bulk approval processed successfully',
            'processed_count' => 3,
            'success_count' => 3,
            'failure_count' => 0,
        ]);
    }

    /** @test */
    public function delegation_functionality()
    {
        $this->matrix = ApprovalMatrix::factory()->create(['approval_levels' => 1]);

        $primaryApprover = ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => $this->approver1->id,
            'approval_level' => 1,
            'is_primary' => true,
            'can_delegate' => true,
        ]);

        // Create delegation
        $delegate = User::factory()->create();
        $delegation = \App\Models\ApprovalMatrixDelegation::factory()->create([
            'approval_matrix_approver_id' => $primaryApprover->id,
            'delegate_from_user_id' => $this->approver1->id,
            'delegate_to_user_id' => $delegate->id,
            'start_date' => now(),
            'end_date' => now()->addDays(7),
            'is_active' => true,
        ]);

        $storeOrder = StoreOrder::factory()->create();
        $workflow = $this->workflowService->initiateWorkflow($storeOrder, 'store_orders', $this->requester);

        // Original approver should not be able to see workflow (delegation active)
        Sanctum::actingAs($this->approver1);
        $response = $this->getJson('/api/pending-approvals');
        $this->assertCount(0, $response->json('data'));

        // Delegate should be able to see and approve the workflow
        Sanctum::actingAs($delegate);
        $response = $this->getJson('/api/pending-approvals');
        $this->assertCount(1, $response->json('data'));

        $this->postJson("/api/approval-workflows/{$workflow->id}/process", [
            'action' => 'approved',
            'action_reason' => 'Approved as delegate',
        ])->assertStatus(200);

        $workflow->refresh();
        $this->assertEquals('approved', $workflow->current_status);

        // Check delegation was recorded
        $step = $workflow->steps->first();
        $this->assertEquals($delegate->id, $step->delegated_to_user_id);
    }

    /** @test */
    public function escalation_for_overdue_approvals()
    {
        $this->matrix = ApprovalMatrix::factory()->create(['approval_levels' => 2]);

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => $this->approver1->id,
            'approval_level' => 1,
            'approval_deadline_hours' => 1, // 1 hour deadline
            'business_hours_only' => false,
        ]);

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => $this->approver2->id,
            'approval_level' => 2,
        ]);

        $storeOrder = StoreOrder::factory()->create();
        $workflow = $this->workflowService->initiateWorkflow($storeOrder, 'store_orders', $this->requester);

        // Simulate deadline passing
        $step = $workflow->steps()->where('approval_level', 1)->first();
        $step->update(['deadline_at' => now()->subHours(2)]);

        // Check overdue workflows
        Sanctum::actingAs($this->requester);
        $response = $this->getJson('/api/approval-workflows/overdue');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));

        // Escalate workflow
        $response = $this->postJson("/api/approval-workflows/{$workflow->id}/escalate", [
            'escalation_reason' => 'Primary approver missed deadline - escalating to level 2',
        ]);

        $response->assertStatus(200);

        $workflow->refresh();
        $this->assertEquals('escalated', $workflow->current_status);
        $this->assertNotNull($workflow->escalated_at);
        $this->assertEquals(2, $workflow->current_approval_level); // Escalated to level 2
    }

    /** @test */
    public function parallel_approval_workflow()
    {
        // Create parallel approval matrix
        $this->matrix = ApprovalMatrix::factory()->create([
            'matrix_name' => 'Parallel Approval Matrix',
            'approval_type' => 'parallel',
            'approval_levels' => 2,
        ]);

        // Create multiple approvers for level 1 (parallel)
        $approver1a = User::factory()->create();
        $approver1b = User::factory()->create();

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => $approver1a->id,
            'approval_level' => 1,
        ]);

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => $approver1b->id,
            'approval_level' => 1,
        ]);

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => $this->approver2->id,
            'approval_level' => 2,
        ]);

        $storeOrder = StoreOrder::factory()->create();
        $workflow = $this->workflowService->initiateWorkflow($storeOrder, 'store_orders', $this->requester);

        // Both level 1 approvers should see the workflow
        Sanctum::actingAs($approver1a);
        $response1 = $this->getJson('/api/pending-approvals');
        $this->assertCount(1, $response1->json('data'));

        Sanctum::actingAs($approver1b);
        $response2 = $this->getJson('/api/pending-approvals');
        $this->assertCount(1, $response2->json('data'));

        // One approver approves (parallel approval should advance)
        Sanctum::actingAs($approver1a);
        $this->postJson("/api/approval-workflows/{$workflow->id}/process", [
            'action' => 'approved',
            'action_reason' => 'Parallel approval - approver 1a',
        ]);

        $workflow->refresh();
        $this->assertEquals('pending', $workflow->current_status); // Still needs level 2
        $this->assertEquals(2, $workflow->current_approval_level); // Advanced to level 2

        // Level 2 approver can now approve
        Sanctum::actingAs($this->approver2);
        $this->postJson("/api/approval-workflows/{$workflow->id}/process", [
            'action' => 'approved',
            'action_reason' => 'Level 2 approval',
        ]);

        $workflow->refresh();
        $this->assertEquals('approved', $workflow->current_status);
    }
}