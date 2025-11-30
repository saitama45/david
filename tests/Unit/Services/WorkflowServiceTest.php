<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\WorkflowService;
use App\Models\ApprovalMatrix;
use App\Models\ApprovalMatrixApprover;
use App\Models\EntityApprovalWorkflow;
use App\Models\ApprovalWorkflowStep;
use App\Models\User;
use App\Models\StoreOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use App\Events\WorkflowInitiated;
use App\Events\ApprovalActionProcessed;
use App\Events\WorkflowCompleted;

class WorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WorkflowService $service;
    protected User $user;
    protected ApprovalMatrix $matrix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkflowService();
        $this->user = User::factory()->create();

        $this->matrix = ApprovalMatrix::factory()->create([
            'matrix_name' => 'Test Matrix',
            'module_name' => 'store_orders',
            'entity_type' => 'regular',
            'approval_levels' => 2,
        ]);

        // Create approvers for the matrix
        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => User::factory()->create()->id,
            'approval_level' => 1,
        ]);

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => User::factory()->create()->id,
            'approval_level' => 2,
        ]);
    }

    /** @test */
    public function it_can_initiate_workflow()
    {
        Event::fake();

        $storeOrder = StoreOrder::factory()->create([
            'total_amount' => 5000,
        ]);

        $workflow = $this->service->initiateWorkflow(
            $storeOrder,
            'store_orders',
            $this->user
        );

        $this->assertInstanceOf(EntityApprovalWorkflow::class, $workflow);
        $this->assertEquals('store_orders', $workflow->entity_type);
        $this->assertEquals($storeOrder->id, $workflow->entity_id);
        $this->assertEquals($this->matrix->approval_levels, $workflow->total_approval_required);
        $this->assertEquals('pending', $workflow->current_status);
        $this->assertEquals($this->user->id, $workflow->initiated_by_id);
        $this->assertEquals(0, $workflow->current_approval_level);

        // Check that workflow steps were created
        $this->assertCount(2, $workflow->steps);

        // Check that event was fired
        Event::assertDispatched(WorkflowInitiated::class, function ($event) use ($workflow) {
            return $event->workflow->id === $workflow->id;
        });
    }

    /** @test */
    public function it_returns_null_when_no_matching_matrix_found()
    {
        $storeOrder = StoreOrder::factory()->create();

        $workflow = $this->service->initiateWorkflow(
            $storeOrder,
            'store_orders',
            $this->user
        );

        $this->assertNull($workflow);
    }

    /** @test */
    public function it_can_process_approval()
    {
        Event::fake();

        // Create workflow first
        $storeOrder = StoreOrder::factory()->create();
        $workflow = $this->service->initiateWorkflow($storeOrder, 'store_orders', $this->user);

        $approver = $workflow->steps->first()->approver;
        $approvalData = [
            'action_reason' => 'Approved - everything looks good',
            'additional_notes' => 'Quick review completed',
        ];

        $result = $this->service->processApproval(
            $workflow,
            $approver,
            'approved',
            $approvalData
        );

        $this->assertTrue($result);

        // Refresh workflow from database
        $workflow->refresh();

        // Check that step was updated
        $step = $workflow->steps()->where('approval_level', 1)->first();
        $this->assertEquals('approved', $step->action);
        $this->assertEquals($approvalData['action_reason'], $step->action_reason);
        $this->assertNotNull($step->action_taken_at);

        // Check that workflow status is still pending (more approvals needed)
        $this->assertEquals('pending', $workflow->current_status);
        $this->assertEquals(1, $workflow->current_approval_level);

        // Check that event was fired
        Event::assertDispatched(ApprovalActionProcessed::class, function ($event) use ($workflow, $step) {
            return $event->workflow->id === $workflow->id
                && $event->step->id === $step->id
                && $event->action === 'approved';
        });
    }

    /** @test */
    public function it_can_complete_workflow_on_final_approval()
    {
        Event::fake();

        // Create workflow
        $storeOrder = StoreOrder::factory()->create();
        $workflow = $this->service->initiateWorkflow($storeOrder, 'store_orders', $this->user);

        // Approve first level
        $firstStep = $workflow->steps()->where('approval_level', 1)->first();
        $this->service->processApproval($workflow, $firstStep->approver, 'approved');

        // Approve second level (final)
        $secondStep = $workflow->steps()->where('approval_level', 2)->first();
        $this->service->processApproval($workflow, $secondStep->approver, 'approved');

        // Refresh workflow
        $workflow->refresh();

        // Workflow should be completed
        $this->assertEquals('approved', $workflow->current_status);
        $this->assertEquals(2, $workflow->current_approval_level);
        $this->assertNotNull($workflow->completed_at);

        // Check that completion event was fired
        Event::assertDispatched(WorkflowCompleted::class, function ($event) use ($workflow) {
            return $event->workflow->id === $workflow->id
                && $event->status === 'approved';
        });
    }

    /** @test */
    public function it_can_reject_workflow()
    {
        Event::fake();

        // Create workflow
        $storeOrder = StoreOrder::factory()->create();
        $workflow = $this->service->initiateWorkflow($storeOrder, 'store_orders', $this->user);

        $approver = $workflow->steps->first()->approver;
        $rejectionReason = 'Budget exceeded - need to review costs';

        $result = $this->service->processApproval(
            $workflow,
            $approver,
            'rejected',
            ['action_reason' => $rejectionReason]
        );

        $this->assertTrue($result);

        // Refresh workflow
        $workflow->refresh();

        // Workflow should be rejected
        $this->assertEquals('rejected', $workflow->current_status);
        $this->assertEquals($rejectionReason, $workflow->rejection_reason);
        $this->assertNotNull($workflow->completed_at);

        // Check that completion event was fired
        Event::assertDispatched(WorkflowCompleted::class, function ($event) use ($workflow, $rejectionReason) {
            return $event->workflow->id === $workflow->id
                && $event->status === 'rejected';
        });
    }

    /** @test */
    public function it_can_get_pending_workflows_for_user()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // Create workflows
        $workflow1 = EntityApprovalWorkflow::factory()->create([
            'current_status' => 'pending',
        ]);

        $workflow2 = EntityApprovalWorkflow::factory()->create([
            'current_status' => 'pending',
        ]);

        $workflow3 = EntityApprovalWorkflow::factory()->create([
            'current_status' => 'approved',
        ]);

        // Create steps
        ApprovalWorkflowStep::factory()->create([
            'entity_approval_workflow_id' => $workflow1->id,
            'approver_user_id' => $user1->id,
            'action' => 'pending',
        ]);

        ApprovalWorkflowStep::factory()->create([
            'entity_approval_workflow_id' => $workflow2->id,
            'approver_user_id' => $user2->id,
            'action' => 'pending',
        ]);

        ApprovalWorkflowStep::factory()->create([
            'entity_approval_workflow_id' => $workflow3->id,
            'approver_user_id' => $user1->id,
            'action' => 'approved',
        ]);

        $pendingWorkflows = $this->service->getPendingWorkflowsForUser($user1);

        $this->assertCount(1, $pendingWorkflows);
        $this->assertEquals($workflow1->id, $pendingWorkflows->first()->id);
    }

    /** @test */
    public function it_can_check_if_user_can_approve_workflow()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $workflow = EntityApprovalWorkflow::factory()->create([
            'current_status' => 'pending',
            'current_approval_level' => 1,
        ]);

        // Create steps
        ApprovalWorkflowStep::factory()->create([
            'entity_approval_workflow_id' => $workflow->id,
            'approval_level' => 1,
            'approver_user_id' => $user1->id,
            'action' => 'pending',
        ]);

        ApprovalWorkflowStep::factory()->create([
            'entity_approval_workflow_id' => $workflow->id,
            'approval_level' => 2,
            'approver_user_id' => $user2->id,
            'action' => 'pending',
        ]);

        // User1 can approve (current level 1)
        $this->assertTrue($this->service->canUserApproveWorkflow($user1, $workflow));

        // User2 cannot approve yet (needs level 2 approval)
        $this->assertFalse($this->service->canUserApproveWorkflow($user2, $workflow));
    }

    /** @test */
    public function it_validates_approval_sequence()
    {
        $storeOrder = StoreOrder::factory()->create();
        $workflow = $this->service->initiateWorkflow($storeOrder, 'store_orders', $this->user);

        $firstStep = $workflow->steps()->where('approval_level', 1)->first();
        $secondStep = $workflow->steps()->where('approval_level', 2)->first();

        // Try to approve level 2 before level 1
        $result = $this->service->processApproval(
            $workflow,
            $secondStep->approver,
            'approved'
        );

        $this->assertFalse($result);

        // Approve level 1 first
        $this->service->processApproval($workflow, $firstStep->approver, 'approved');

        // Now level 2 can be approved
        $result = $this->service->processApproval(
            $workflow,
            $secondStep->approver,
            'approved'
        );

        $this->assertTrue($result);

        $workflow->refresh();
        $this->assertEquals('approved', $workflow->current_status);
    }

    /** @test */
    public function it_handles_workflow_cancellation()
    {
        Event::fake();

        $storeOrder = StoreOrder::factory()->create();
        $workflow = $this->service->initiateWorkflow($storeOrder, 'store_orders', $this->user);

        $result = $this->service->cancelWorkflow($workflow, 'Order cancelled by requester');

        $this->assertTrue($result);

        $workflow->refresh();

        $this->assertEquals('cancelled', $workflow->current_status);
        $this->assertNotNull($workflow->completed_at);

        // All steps should be marked as cancelled or skipped
        $steps = $workflow->steps()->where('action', '!=', 'pending')->get();
        $this->assertCount(2, $steps);

        // Check that completion event was fired
        Event::assertDispatched(WorkflowCompleted::class, function ($event) use ($workflow) {
            return $event->workflow->id === $workflow->id
                && $event->status === 'cancelled';
        });
    }

    /** @test */
    public function it_can_get_workflow_statistics()
    {
        // Create workflows with different statuses
        EntityApprovalWorkflow::factory()->count(3)->create(['current_status' => 'pending']);
        EntityApprovalWorkflow::factory()->count(2)->create(['current_status' => 'approved']);
        EntityApprovalWorkflow::factory()->count(1)->create(['current_status' => 'rejected']);
        EntityApprovalWorkflow::factory()->count(1)->create(['current_status' => 'cancelled']);

        $stats = $this->service->getWorkflowStatistics();

        $this->assertEquals(3, $stats['pending']);
        $this->assertEquals(2, $stats['approved']);
        $this->assertEquals(1, $stats['rejected']);
        $this->assertEquals(1, $stats['cancelled']);
        $this->assertEquals(7, $stats['total']);
    }

    /** @test */
    public function it_creates_deadlines_for_approval_steps()
    {
        // Create matrix with deadline
        ApprovalMatrixApprover::query()->delete();

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => User::factory()->create()->id,
            'approval_level' => 1,
            'approval_deadline_hours' => 24,
        ]);

        ApprovalMatrixApprover::factory()->create([
            'approval_matrix_id' => $this->matrix->id,
            'user_id' => User::factory()->create()->id,
            'approval_level' => 2,
            'approval_deadline_hours' => 48,
        ]);

        $storeOrder = StoreOrder::factory()->create();
        $workflow = $this->service->initiateWorkflow($storeOrder, 'store_orders', $this->user);

        $firstStep = $workflow->steps()->where('approval_level', 1)->first();
        $secondStep = $workflow->steps()->where('approval_level', 2)->first();

        // Check deadlines were set
        $this->assertNotNull($firstStep->deadline_at);
        $this->assertNotNull($secondStep->deadline_at);

        // Check that second step deadline is later than first
        $this->assertTrue(
            $secondStep->deadline_at->greaterThan($firstStep->deadline_at)
        );
    }
}