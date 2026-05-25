<?php

namespace App\Http\Controllers;

use App\Exports\AdoptionRateTrackingExport;
use App\Http\Services\AdoptionRateTrackingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class AdoptionRateTrackingController extends Controller
{
    public function __construct(private AdoptionRateTrackingService $service)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->only([
            'date_from',
            'date_to',
            'store_ids',
            'ordering_templates',
            'search',
            'per_page',
            'tab',
        ]);

        $data = $this->service->getReportData($filters, $request->user());
        $options = $this->service->getFilterOptions($request->user());

        return Inertia::render('Reports/AdoptionRateTracking/Index', [
            'rows' => $data['rows'],
            'totals' => $data['totals'],
            'filters' => $data['filters'],
            'stores' => $options['stores'],
            'assignedStoreIds' => $options['assignedStoreIds'],
            'orderingTemplates' => $options['orderingTemplates'],
            'tabs' => $this->tabs(),
            'canEditRemarks' => $request->user()->can('edit adoption rate tracking remarks'),
        ]);
    }

    public function export(Request $request)
    {
        $filters = $request->only([
            'date_from',
            'date_to',
            'store_ids',
            'ordering_templates',
            'search',
            'per_page',
            'tab',
        ]);

        $tab = $this->service->resolveTab($filters);
        $data = $this->service->getReportData($filters, $request->user(), false);

        return Excel::download(
            new AdoptionRateTrackingExport($data['rows'], $tab),
            'adoption-rate-tracking-' . str_replace('_', '-', $tab) . '-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function updateRemark(Request $request)
    {
        $validated = $request->validate([
            'tab_key' => ['required', 'string', 'in:ordering_timeliness,commit_order_timeliness,delivery_logging_timeliness,sales_upload_timeliness,wastage_upload_timeliness,overall_adoption_rate'],
            'ordering_template' => ['required', 'string', 'max:255'],
            'store_branch_id' => ['required', 'integer', 'exists:store_branches,id'],
            'delivery_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $remark = $this->service->saveRemark($validated, $request->user());

        return response()->json([
            'remarks' => $remark->remarks,
        ]);
    }

    private function tabs(): array
    {
        return [
            ['key' => 'ordering_timeliness', 'label' => 'Ordering Timeliness', 'enabled' => true],
            ['key' => 'commit_order_timeliness', 'label' => 'Commit Order Timeliness (FG & Traded)', 'enabled' => true],
            ['key' => 'delivery_logging_timeliness', 'label' => 'Delivery Logging Timeliness', 'enabled' => true],
            ['key' => 'sales_upload_timeliness', 'label' => 'Sales Upload Timeliness', 'enabled' => true],
            ['key' => 'wastage_upload_timeliness', 'label' => 'Wastage Upload Timeliness', 'enabled' => true],
            ['key' => 'overall_adoption_rate', 'label' => 'Overall Adoption Rate', 'enabled' => true],
        ];
    }
}
