<?php

namespace App\Http\Services\RuleExceptions;

use App\Http\Services\AdoptionRateTrackingService;
use App\Http\Services\OrderingCutoffService;
use App\Models\User;
use Carbon\Carbon;

/**
 * Excuse rules: receiving.late_logging, sales.late_upload, wastage.late_upload.
 *
 * These functions are never blocked; lateness is only scored in the Adoption
 * Rate report. The subject is a report row (`row_key` = template|store|date),
 * recomputed live for that one store and date so a request can only be raised
 * for a row the report really scores `No`.
 */
class AdoptionExcuseEvaluator extends BaseEvaluator
{
    public const SOURCES = [
        'receiving.late_logging' => [
            'method' => 'getDeliveryLoggingTimelinessData',
            'status' => 'on_time',
            'date' => 'sap_dr_date',
            'label' => 'Receiving for delivery on',
        ],
        'sales.late_upload' => [
            'method' => 'getSalesUploadTimelinessData',
            'status' => 'sales_report_uploaded_on_time',
            'date' => 'date_of_sales',
            'label' => 'Sales upload for',
        ],
        'wastage.late_upload' => [
            'method' => 'getWastageUploadTimelinessData',
            'status' => 'wastage_report_uploaded',
            'date' => 'date_of_wastage',
            'label' => 'Wastage recorded for',
        ],
    ];

    private string $ruleKey;

    public function __construct(OrderingCutoffService $cutoffs, private AdoptionRateTrackingService $adoption)
    {
        parent::__construct($cutoffs);
    }

    public function forRule(string $ruleKey): static
    {
        $this->ruleKey = $ruleKey;

        return $this;
    }

    /** @return array{0:int,1:string}|null store id and date parsed from a row key */
    public static function parseRowKey(string $rowKey): ?array
    {
        $parts = explode('|', $rowKey);

        if (count($parts) < 3 || ! ctype_digit($parts[count($parts) - 2])) {
            return null;
        }

        $date = $parts[count($parts) - 1];

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? [(int) $parts[count($parts) - 2], $date] : null;
    }

    public function inputRules(): array
    {
        return ['row_key' => ['required', 'string', 'max:191']];
    }

    public function resolve(array $input, User $user): RuleSubject
    {
        $rowKey = (string) $input['row_key'];
        $parsed = self::parseRowKey($rowKey);

        if (! $parsed) {
            $this->deny('That report item was not recognised.');
        }

        [$storeId, $date] = $parsed;
        $store = $this->activeStore($storeId);
        $row = $this->row($rowKey, $storeId, $date, $user);

        if (! $row) {
            $this->deny('That item is not in the Adoption Rate report for this store and date.');
        }

        $source = self::SOURCES[$this->ruleKey];

        return new RuleSubject($storeId, $rowKey, [
            'row_key' => $rowKey,
            'date' => $date,
            'template' => $row['ordering_template'] ?? null,
            'reference' => $row['wastage_no'] ?? $row['dr_number'] ?? null,
            'action_deadline' => $row['action_deadline'] ?? null,
            'store_name' => $store->name,
            'summary' => $source['label'].' '.Carbon::parse($date)->format('M j, Y'),
        ]);
    }

    public function notNeededReason(RuleSubject $subject, User $user, Carbon $now): ?string
    {
        $row = $this->row($subject->subjectKey, $subject->storeBranchId, $subject->context['date'], $user);
        $status = $row[self::SOURCES[$this->ruleKey]['status']] ?? null;

        if ($status !== 'No') {
            return 'The report does not score this item as late, so there is nothing to excuse.';
        }

        $days = (int) (config("rule_exceptions.rules.{$this->ruleKey}.filing_window_days") ?? 7);
        $deadline = ! empty($row['action_deadline'])
            ? Carbon::parse($row['action_deadline'], OrderingCutoffService::TIMEZONE)
            : Carbon::parse($subject->context['date'], OrderingCutoffService::TIMEZONE)->endOfDay();

        return $now->gt($deadline->copy()->addDays($days))
            ? "Excuses must be requested within {$days} days of the deadline."
            : null;
    }

    private function row(string $rowKey, int $storeId, string $date, User $user): ?array
    {
        $method = self::SOURCES[$this->ruleKey]['method'];

        $data = $this->adoption->{$method}([
            'date_from' => $date,
            'date_to' => $date,
            'store_ids' => [$storeId],
        ], $user, false);

        return collect($data['rows'] ?? [])->firstWhere('row_key', $rowKey);
    }
}
