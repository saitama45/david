<?php

namespace App\Http\Controllers;

use App\Http\Services\MonthEndCountIncidentReportService;
use App\Http\Services\MonthEndCountReadinessService;
use App\Models\Entity;
use App\Models\MonthEndCountIncidentReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class MonthEndCountIncidentReportController extends Controller
{
    public function __construct(
        private MonthEndCountIncidentReportService $incidentReports,
        private MonthEndCountReadinessService $readiness,
    ) {}

    /**
     * The store files its report: a reason for each pending, the action taken and the
     * date it will have finished what is still pending.
     */
    public function file(Request $request, MonthEndCountIncidentReport $report)
    {
        $this->authorizeBranch($report);

        $validated = $request->validate([
            'reasons' => 'required|array',
            'reasons.*' => 'nullable|string|max:1000',
            'action_taken' => 'required|string|max:2000',
            'target_date' => 'nullable|date|after_or_equal:today',
        ]);

        $problem = $this->incidentReports->file(
            $report,
            $validated['reasons'],
            trim($validated['action_taken']),
            $validated['target_date'] ?? null,
            Auth::user(),
            Carbon::today(MonthEndCountIncidentReportService::TZ)
        );

        if ($problem) {
            return back()->withErrors(['error' => $problem]);
        }

        return back()->with('success', "Incident Report {$report->number} filed.");
    }

    /** The filed report, as a PDF shown in the browser. */
    public function pdf(MonthEndCountIncidentReport $report)
    {
        $this->authorizeBranch($report, officeToo: true);
        abort_unless($report->isFiled(), 404, 'This Incident Report has not been filed yet.');

        $report->load(['schedule', 'branch', 'filer:id,first_name,last_name']);
        [$from, $through] = $this->readiness->uploadPeriod($report->schedule, Carbon::today(MonthEndCountIncidentReportService::TZ));

        return Pdf::loadView('pdf.month-end-count-incident-report', [
            'report' => $report,
            'entity' => Entity::find($report->entity_id),
            'periodFrom' => Carbon::parse($from),
            'periodThrough' => Carbon::parse($through),
            'filedBy' => $report->filer ? trim($report->filer->first_name.' '.$report->filer->last_name) : 'N/A',
            'generatedAt' => Carbon::now(MonthEndCountIncidentReportService::TZ),
        ])->setPaper('a4', 'portrait')->stream($report->number.'.pdf');
    }

    /**
     * A store user reaches only the reports of the branches assigned to them. The office,
     * which follows every store's count in Store Progress, may read any of them.
     */
    private function authorizeBranch(MonthEndCountIncidentReport $report, bool $officeToo = false): void
    {
        $user = Auth::user();

        if ($officeToo && $user->can('view month end schedules')) {
            return;
        }

        if (! $user->store_branches()->where('store_branches.id', $report->branch_id)->exists()) {
            abort(403, 'You do not have access to this branch.');
        }
    }
}
