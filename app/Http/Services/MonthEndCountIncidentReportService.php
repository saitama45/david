<?php

namespace App\Http\Services;

use App\Models\MonthEndCountIncidentReport;
use App\Models\MonthEndSchedule;
use App\Models\StoreBranch;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Incident Report a store owes when it still has unfinished transactions after the
 * MEC Scheduled Date of a count it has not submitted.
 *
 * Nothing sweeps the stores: the report is opened the moment the server sees those
 * pendings - on the Month End Count page, a template download or an upload. From then
 * on the store can neither take the template nor upload that count until it has filed
 * the report, even once the pendings themselves are finished. The process is switched
 * per entity in the Month End Count configuration (incident_report_required).
 */
class MonthEndCountIncidentReportService
{
    public const TZ = 'Asia/Manila';

    public function __construct(
        private MonthEndCountSettingsService $settings,
        private MonthEndCountReadinessService $readiness,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->current()['incident_report_required'];
    }

    /**
     * Opens a report for each branch that still has pendings on a count whose date has
     * passed, and keeps adding what is found until the report is filed - so the store
     * explains everything that was late, not only what is left when it files.
     *
     * The caller passes only branches that still owe this count.
     *
     * @param  array<int, list<array{key: string, label: string, count: int}>>  $blockersByBranch  as MonthEndCountReadinessService::blockers() returns them
     */
    public function record(MonthEndSchedule $schedule, array $blockersByBranch, Carbon $today): void
    {
        if (! $this->enabled() || $schedule->calculated_date->toDateString() >= $today->toDateString()) {
            return;
        }

        foreach ($blockersByBranch as $branchId => $blockers) {
            if ($blockers === []) {
                continue;
            }

            $report = MonthEndCountIncidentReport::firstOrCreate(
                ['month_end_schedule_id' => $schedule->id, 'branch_id' => (int) $branchId],
                ['entity_id' => $schedule->entity_id, 'required_at' => Carbon::now(self::TZ), 'pendings' => []]
            );

            if ($report->isFiled()) {
                continue;
            }

            // One entry per pending, at the highest count seen: "3 orders not yet received"
            // stays on the report after two of them are received.
            $pendings = collect($report->pendings)->keyBy('key');
            foreach ($blockers as $blocker) {
                if ((int) $blocker['count'] > (int) ($pendings[$blocker['key']]['count'] ?? 0)) {
                    $pendings[$blocker['key']] = ['key' => $blocker['key'], 'label' => $blocker['label'], 'count' => (int) $blocker['count']];
                }
            }

            if ($pendings->values()->all() != $report->pendings) {
                $report->update(['pendings' => $pendings->values()->all()]);
            }
        }
    }

    /**
     * The reports of these branches on this count.
     *
     * @return Collection<int, MonthEndCountIncidentReport> keyed by branch id
     */
    public function reportsFor(MonthEndSchedule $schedule, $branchIds): Collection
    {
        return MonthEndCountIncidentReport::with('filer:id,first_name,last_name')
            ->where('month_end_schedule_id', $schedule->id)
            ->whereIn('branch_id', collect($branchIds)->map(fn ($id) => (int) $id)->all())
            ->get()
            ->keyBy('branch_id');
    }

    /**
     * Why the branch cannot take the template of this count or upload it yet: it owes an
     * Incident Report. Null when nothing is owed, or while the process is switched off.
     */
    public function problem(?MonthEndSchedule $schedule, int $branchId): ?string
    {
        if (! $schedule || ! $this->enabled()) {
            return null;
        }

        $report = MonthEndCountIncidentReport::where('month_end_schedule_id', $schedule->id)
            ->where('branch_id', $branchId)
            ->whereNull('filed_at')
            ->first();

        if (! $report) {
            return null;
        }

        $branch = StoreBranch::find($branchId);

        return ($branch?->name ?? 'This branch').' still had unfinished transactions after the MEC Scheduled Date ('
            .$schedule->calculated_date->format('M j, Y').'). File the Incident Report on the Month End Count page first.';
    }

    /**
     * Files the report: a reason for every pending on it, the action taken, and the date
     * the store will have finished what is still pending. A filed report is final.
     *
     * @param  array<string, string>  $reasons  pending key => reason
     * @return ?string what is wrong, or null once the report is filed
     */
    public function file(MonthEndCountIncidentReport $report, array $reasons, string $actionTaken, ?string $targetDate, User $user, Carbon $today): ?string
    {
        return DB::transaction(function () use ($report, $reasons, $actionTaken, $targetDate, $user, $today) {
            $report = MonthEndCountIncidentReport::whereKey($report->id)->lockForUpdate()->firstOrFail();

            if ($report->isFiled()) {
                return 'This Incident Report was already filed.';
            }

            $stillPending = array_column(
                $this->readiness->blockersForUpload($report->schedule, [$report->branch_id], $today)[$report->branch_id] ?? [],
                'key'
            );

            $pendings = [];
            foreach ($report->pendings as $pending) {
                $reason = trim((string) ($reasons[$pending['key']] ?? ''));
                if ($reason === '') {
                    return 'Give a reason for every pending on the report. Refresh the page if the list has changed.';
                }
                $pendings[] = $pending + ['reason' => $reason, 'open' => in_array($pending['key'], $stillPending, true)];
            }

            if (! $targetDate && in_array(true, array_column($pendings, 'open'), true)) {
                return 'Give the target date for finishing what is still pending.';
            }

            $report->update([
                'pendings' => $pendings,
                'action_taken' => $actionTaken,
                'target_date' => $targetDate,
                'filed_by' => $user->id,
                'filed_at' => Carbon::now(self::TZ),
            ]);

            return null;
        });
    }

    /**
     * What the pages show of a report. Each pending says whether it is still open: as it
     * was when the report was filed, or - before that - as the caller finds it now.
     *
     * @param  list<string>  $stillPendingKeys  keys of the branch's current blockers
     */
    public function toArray(MonthEndCountIncidentReport $report, array $stillPendingKeys = []): array
    {
        return [
            'id' => $report->id,
            'number' => $report->number,
            'branch_id' => (int) $report->branch_id,
            'schedule_id' => (int) $report->month_end_schedule_id,
            'filed' => $report->isFiled(),
            'required_at' => $report->required_at->timezone(self::TZ)->format('M j, Y g:i A'),
            'filed_at' => $report->filed_at?->timezone(self::TZ)->format('M j, Y g:i A'),
            'filed_by' => $report->filer ? trim($report->filer->first_name.' '.$report->filer->last_name) : null,
            'pendings' => collect($report->pendings)->map(fn ($pending) => [
                'key' => $pending['key'],
                'label' => $pending['label'],
                'open' => $pending['open'] ?? in_array($pending['key'], $stillPendingKeys, true),
            ])->all(),
            'file_url' => route('month-end-count.incident-reports.file', $report->id),
            'pdf_url' => $report->isFiled() ? route('month-end-count.incident-reports.pdf', $report->id) : null,
        ];
    }
}
