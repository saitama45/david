<?php

namespace App\Exports;

use App\Models\RuleExceptionRequest;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Business-rule exception log for audit and monitoring: one row per request
 * with who asked, why, who decided, and whether an unlock grant was used.
 */
class RuleExceptionLogExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(private Builder $query) {}

    public function query()
    {
        return $this->query
            ->with(['storeBranch:id,name', 'requester:id,first_name,last_name', 'decider:id,first_name,last_name', 'consumer:id,first_name,last_name'])
            ->orderByDesc('requested_at');
    }

    public function headings(): array
    {
        return [
            'Request #', 'Requested At', 'Module', 'Rule', 'Type', 'Item', 'Store',
            'Requested By', 'Reason', 'Justification', 'Status', 'Decided By', 'Decided At',
            'Decision Remarks', 'Valid Until', 'Used By', 'Used At', 'Used For', 'Hours To Decision',
        ];
    }

    /** @param RuleExceptionRequest $row */
    public function map($row): array
    {
        $name = fn ($user) => $user ? trim("{$user->first_name} {$user->last_name}") : '';

        return [
            $row->id,
            $row->requested_at?->format('Y-m-d H:i'),
            $row->module,
            $row->context['rule_label'] ?? $row->rule_key,
            $row->type,
            $row->context['summary'] ?? $row->subject_key,
            $row->storeBranch?->name,
            $name($row->requester),
            config("rule_exceptions.reasons.{$row->reason_code}", $row->reason_code),
            $row->justification,
            $row->status->label(),
            $name($row->decider),
            $row->decided_at?->format('Y-m-d H:i'),
            $row->decision_remarks,
            $row->valid_until?->format('Y-m-d H:i'),
            $name($row->consumer),
            $row->consumed_at?->format('Y-m-d H:i'),
            $row->consumed_ref_type ? "{$row->consumed_ref_type}: {$row->consumed_ref_id}" : '',
            $row->decided_at && $row->requested_at ? round($row->requested_at->diffInMinutes($row->decided_at) / 60, 1) : '',
        ];
    }
}
