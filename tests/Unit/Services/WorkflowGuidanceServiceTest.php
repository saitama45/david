<?php

namespace Tests\Unit\Services;

use App\Http\Services\WorkflowGuidanceService;
use App\Models\User;
use Tests\TestCase;

class WorkflowGuidanceServiceTest extends TestCase
{
    public function test_late_completed_orders_are_not_missing_submissions(): void
    {
        $service = new WorkflowGuidanceService;
        $row = ['order_exists' => true, 'plotted' => 'No'];
        $this->assertSame([], $service->reportActions(new User, 'ordering_timeliness', $row));
        $metrics = $service->reportMetrics('ordering_timeliness', collect([$row, ['order_exists' => false, 'plotted' => 'No order']]));
        $this->assertSame(1, $metrics['applicable']);
        $this->assertSame(100.0, $metrics['completion_rate']);
        $this->assertSame(0.0, $metrics['on_time_rate']);
        $this->assertSame(0, $metrics['outstanding']);
    }

    public function test_late_sales_and_excluded_commitments_do_not_request_resubmission(): void
    {
        $service = new WorkflowGuidanceService;
        $this->assertSame([], $service->reportActions(new User, 'sales_upload_timeliness', ['sales_report_uploaded' => 'Yes', 'sales_report_uploaded_on_time' => 'No']));
        $metrics = $service->reportMetrics('commit_order_timeliness', collect([
            ['fg_on_time' => 'NA', 'fg_commit_date_display' => 'NA', 'traded_on_time' => 'No', 'traded_commit_date_display' => 'Sep 8, 2026'],
        ]));
        $this->assertSame(1, $metrics['applicable']);
        $this->assertSame(100.0, $metrics['completion_rate']);
        $this->assertSame(0.0, $metrics['on_time_rate']);
    }

    public function test_branch_and_supplier_assignments_limit_action_ownership(): void
    {
        $service = new class extends WorkflowGuidanceService
        {
            public function catalog(User $viewer): array
            {
                return ['fg_commit' => [
                    'label' => 'Commit', 'rule' => 'Rule', 'can_act' => true, 'url' => null,
                    'people' => [
                        ['id' => 1, 'roles' => ['GSI-Prod'], 'branches' => [10], 'suppliers' => [20]],
                        ['id' => 2, 'roles' => ['GSI-BOM'], 'branches' => [11], 'suppliers' => [20]],
                        ['id' => 3, 'roles' => ['Finished Goods CS'], 'branches' => [10], 'suppliers' => [21]],
                    ],
                ]];
            }
        };
        $viewer = new User;
        $viewer->id = 1;
        $task = $service->task($viewer, 'fg_commit', ['branch_id' => 10, 'supplier_id' => 20]);
        $this->assertSame('mine', $task['ownership']);
        $this->assertSame(['GSI-Prod'], $task['roles']);
        $this->assertCount(1, $task['people']);
        $task = $service->task($viewer, 'fg_commit', ['branch_id' => 99, 'supplier_id' => 20]);
        $this->assertSame('unassigned', $task['ownership']);
        $this->assertFalse($task['can_act']);
    }

    public function test_wastage_metrics_explain_the_missing_occurrence_date(): void
    {
        $metrics = (new WorkflowGuidanceService)->reportMetrics('wastage_upload_timeliness', collect([['wastage_report_uploaded' => 'Yes']]));
        $this->assertStringContainsString('Missing wastage', $metrics['limitation']);
    }
}
