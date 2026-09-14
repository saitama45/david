<?php

namespace App\Http\Services;

use App\Enums\RuleExceptionStatus;
use App\Http\Services\RuleExceptions\AdoptionExcuseEvaluator;
use App\Http\Services\RuleExceptions\DtsEditEvaluator;
use App\Http\Services\RuleExceptions\DtsLateOrderEvaluator;
use App\Http\Services\RuleExceptions\MassOrderEditEvaluator;
use App\Http\Services\RuleExceptions\MassOrderLateOrderEvaluator;
use App\Http\Services\RuleExceptions\MecUploadWindowEvaluator;
use App\Http\Services\RuleExceptions\RuleEvaluator;
use App\Http\Services\RuleExceptions\RuleSubject;
use App\Models\RuleExceptionRequest;
use App\Models\RuleExceptionRequestAction;
use App\Models\User;
use App\Models\UserAssignedStoreBranch;
use App\Support\EntityContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Business-rule exception requests: submit, decide, consume, expire.
 *
 * Every state change runs in a transaction on a locked row, re-validates the
 * subject against the live data, and writes one append-only action entry.
 * Controls enforced here (not only in the UI):
 *  - requester holds the rule's performer permission and is assigned to the store
 *  - approver holds the module's approver permission, is assigned to the store,
 *    and is never the requester
 *  - only a rule that actually blocks (or an item actually scored late) can be
 *    excepted, and only one open request exists per subject
 *  - an unlock grant is single-use and time-boxed
 */
class RuleExceptionService
{
    private const EVALUATORS = [
        'mec.upload_window' => MecUploadWindowEvaluator::class,
        'mass_order.late_order' => MassOrderLateOrderEvaluator::class,
        'mass_order.edit_after_cutoff' => MassOrderEditEvaluator::class,
        'dts_mass_order.late_order' => DtsLateOrderEvaluator::class,
        'dts_mass_order.edit_locked' => DtsEditEvaluator::class,
        'receiving.late_logging' => AdoptionExcuseEvaluator::class,
        'sales.late_upload' => AdoptionExcuseEvaluator::class,
        'wastage.late_upload' => AdoptionExcuseEvaluator::class,
    ];

    public function __construct(private OrderingCutoffService $cutoffs) {}

    public function now(): Carbon
    {
        return $this->cutoffs->now();
    }

    // --- registry --------------------------------------------------------------

    public function rules(): array
    {
        return config('rule_exceptions.rules', []);
    }

    public function rule(string $ruleKey): array
    {
        $rule = $this->rules()[$ruleKey] ?? null;

        if (! $rule || ! isset(self::EVALUATORS[$ruleKey])) {
            throw ValidationException::withMessages(['rule_key' => 'Unknown business rule.']);
        }

        return $rule + ['key' => $ruleKey];
    }

    public function evaluator(string $ruleKey): RuleEvaluator
    {
        $this->rule($ruleKey);
        $evaluator = app(self::EVALUATORS[$ruleKey]);

        if ($evaluator instanceof AdoptionExcuseEvaluator) {
            $evaluator->forRule($ruleKey);
        }

        return $evaluator;
    }

    // --- permissions -------------------------------------------------------------

    /** @param int[] $storeIds */
    public function isAssignedToStores(User $user, array $storeIds): bool
    {
        $assigned = UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_diff(array_map('intval', $storeIds), $assigned) === [];
    }

    public function canRequest(User $user, string $ruleKey, RuleSubject $subject): bool
    {
        $rule = $this->rule($ruleKey);

        foreach ($rule['request_permissions'] as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return $this->isAssignedToStores($user, $subject->stores());
    }

    /** Whether the user may approve or reject this request. Never the requester. */
    public function canDecide(User $user, RuleExceptionRequest $request): bool
    {
        $rule = $this->rules()[$request->rule_key] ?? null;

        return $rule
            && (int) $request->requested_by !== (int) $user->id
            && $user->can($rule['approve_permission'])
            && $this->isAssignedToStores($user, $request->context['store_ids'] ?? [$request->store_branch_id]);
    }

    /** Pending requests the user may decide: their approver rules, their stores, not their own. */
    public function decidableQuery(User $user): Builder
    {
        $ruleKeys = collect($this->rules())
            ->filter(fn ($rule) => $user->can($rule['approve_permission']))
            ->keys()
            ->all();

        $storeIds = UserAssignedStoreBranch::where('user_id', $user->id)->pluck('store_branch_id')->all();

        return RuleExceptionRequest::query()
            ->where('status', RuleExceptionStatus::PENDING->value)
            ->whereIn('rule_key', $ruleKeys ?: ['__none__'])
            ->whereIn('store_branch_id', $storeIds ?: [0])
            ->where('requested_by', '!=', $user->id);
    }

    // --- lifecycle ---------------------------------------------------------------

    /**
     * Resolve a subject for a rule and confirm the user may ask for it.
     * Used both on submit and to tell the UI whether to offer the request link.
     */
    public function resolveForRequest(string $ruleKey, array $input, User $user): RuleSubject
    {
        $evaluator = $this->evaluator($ruleKey);
        $subject = $evaluator->resolve($input, $user);

        if (! $this->canRequest($user, $ruleKey, $subject)) {
            throw new HttpException(403, 'You are not allowed to request this exception for the selected store.');
        }

        if ($reason = $evaluator->notNeededReason($subject, $user, $this->now())) {
            throw ValidationException::withMessages(['subject' => $reason]);
        }

        return $subject;
    }

    /**
     * @param  array{rule_key:string,reason_code:string,justification:string}  $data
     */
    public function submit(User $user, array $data, array $subjectInput, ?UploadedFile $attachment = null): RuleExceptionRequest
    {
        $rule = $this->rule($data['rule_key']);
        $this->validateReason($data, $attachment);

        $subject = $this->resolveForRequest($rule['key'], $subjectInput, $user);
        $this->assertNoOpenRequest($rule['key'], $subject->subjectKey);

        $path = $attachment?->store('rule-exceptions', 'local');

        try {
            return DB::transaction(function () use ($user, $rule, $data, $subject, $path) {
                $now = $this->now();

                $request = RuleExceptionRequest::create([
                    'entity_id' => app(EntityContext::class)->id(),
                    'store_branch_id' => $subject->storeBranchId,
                    'rule_key' => $rule['key'],
                    'module' => $rule['module'],
                    'type' => $rule['type'],
                    'subject_key' => $subject->subjectKey,
                    'context' => $subject->context + ['store_ids' => $subject->stores(), 'rule_label' => $rule['label']],
                    'reason_code' => $data['reason_code'],
                    'justification' => trim($data['justification']),
                    'attachment_path' => $path,
                    'status' => RuleExceptionStatus::PENDING,
                    'requested_by' => $user->id,
                    'requested_at' => $now,
                ]);

                $this->log($request, 'submitted', null, RuleExceptionStatus::PENDING, $user, null);

                return $request;
            });
        } catch (QueryException $e) {
            // Lost a race with an identical request: the filtered unique index held.
            if (str_contains($e->getMessage(), 'rer_open_subject_unique')) {
                throw ValidationException::withMessages(['subject' => 'An exception request for this is already open.']);
            }

            throw $e;
        }
    }

    public function approve(User $user, RuleExceptionRequest $request, ?string $validUntil, ?string $remarks): RuleExceptionRequest
    {
        return DB::transaction(function () use ($user, $request, $validUntil, $remarks) {
            $request = $this->lockPending($request);
            $this->assertCanDecide($user, $request);
            $rule = $this->rule($request->rule_key);
            $now = $this->now();

            // Re-check against live data: the order may have been committed or the
            // delivery date may have arrived while the request waited.
            $evaluator = $this->evaluator($request->rule_key);
            $subject = $evaluator->resolve($this->subjectInputFrom($request), $request->requester);

            if ($reason = $evaluator->notNeededReason($subject, $request->requester, $now)) {
                throw ValidationException::withMessages(['subject' => $reason]);
            }

            $until = null;

            if ($request->isUnlock()) {
                $latest = $this->latestValidUntil($rule, $evaluator, $subject, $now);
                $until = $validUntil ? Carbon::parse($validUntil, OrderingCutoffService::TIMEZONE) : $this->defaultValidUntil($rule, $latest, $now);

                if ($until->lte($now)) {
                    throw ValidationException::withMessages(['valid_until' => 'The exception must stay valid until a future time.']);
                }

                if ($until->gt($latest)) {
                    throw ValidationException::withMessages([
                        'valid_until' => 'The exception can be valid until '.$latest->format('M j, Y g:i A').' at the latest.',
                    ]);
                }
            }

            $request->update([
                'status' => RuleExceptionStatus::APPROVED,
                'decided_by' => $user->id,
                'decided_at' => $now,
                'decision_remarks' => $remarks ? trim($remarks) : null,
                'valid_until' => $until,
            ]);

            $this->log($request, 'approved', RuleExceptionStatus::PENDING, RuleExceptionStatus::APPROVED, $user, $remarks);

            return $request;
        });
    }

    public function reject(User $user, RuleExceptionRequest $request, string $remarks): RuleExceptionRequest
    {
        if (mb_strlen(trim($remarks)) < 5) {
            throw ValidationException::withMessages(['decision_remarks' => 'Explain why the request is rejected.']);
        }

        return DB::transaction(function () use ($user, $request, $remarks) {
            $request = $this->lockPending($request);
            $this->assertCanDecide($user, $request);

            $request->update([
                'status' => RuleExceptionStatus::REJECTED,
                'decided_by' => $user->id,
                'decided_at' => $this->now(),
                'decision_remarks' => trim($remarks),
            ]);

            $this->log($request, 'rejected', RuleExceptionStatus::PENDING, RuleExceptionStatus::REJECTED, $user, $remarks);

            return $request;
        });
    }

    /** Only the requester may withdraw, and only while pending. */
    public function cancel(User $user, RuleExceptionRequest $request): RuleExceptionRequest
    {
        return DB::transaction(function () use ($user, $request) {
            $request = $this->lockPending($request);

            if ((int) $request->requested_by !== (int) $user->id) {
                throw new HttpException(403, 'Only the requester can cancel this request.');
            }

            $request->update([
                'status' => RuleExceptionStatus::CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => $this->now(),
            ]);

            $this->log($request, 'cancelled', RuleExceptionStatus::PENDING, RuleExceptionStatus::CANCELLED, $user, null);

            return $request;
        });
    }

    /** An approved, unused, unexpired unlock grant for this subject (read-only, for UI). */
    public function usableGrant(string $ruleKey, string $subjectKey): ?RuleExceptionRequest
    {
        return RuleExceptionRequest::query()
            ->where('rule_key', $ruleKey)
            ->where('subject_key', $subjectKey)
            ->where('type', 'unlock')
            ->where('status', RuleExceptionStatus::APPROVED->value)
            ->where('valid_until', '>=', $this->now()->format('Y-m-d H:i:s'))
            ->first();
    }

    /**
     * Consume the grant for a subject. MUST be called inside the database
     * transaction that performs the protected action, so a failed action
     * rolls the consumption back. The row lock makes a second use fail.
     */
    public function consume(string $ruleKey, string $subjectKey, User $user, string $refType, string|int|null $refId): RuleExceptionRequest
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Rule exception grants must be consumed inside the protected transaction.');
        }

        $grant = RuleExceptionRequest::query()
            ->where('rule_key', $ruleKey)
            ->where('subject_key', $subjectKey)
            ->where('type', 'unlock')
            ->where('status', RuleExceptionStatus::APPROVED->value)
            ->lockForUpdate()
            ->first();

        $now = $this->now();

        if (! $grant || $grant->valid_until === null || $now->gt(Carbon::parse($grant->valid_until->format('Y-m-d H:i:s'), OrderingCutoffService::TIMEZONE))) {
            throw ValidationException::withMessages(['subject' => 'No approved, unexpired exception covers this action.']);
        }

        $rule = $this->rule($ruleKey);

        foreach ($rule['request_permissions'] as $permission) {
            if (! $user->can($permission)) {
                throw new HttpException(403, 'You are not allowed to use this exception.');
            }
        }

        if (! $this->isAssignedToStores($user, $grant->context['store_ids'] ?? [$grant->store_branch_id])) {
            throw new HttpException(403, 'You are not assigned to the store this exception covers.');
        }

        $grant->update([
            'status' => RuleExceptionStatus::CONSUMED,
            'consumed_by' => $user->id,
            'consumed_at' => $now,
            'consumed_ref_type' => $refType,
            'consumed_ref_id' => $refId === null ? null : (string) $refId,
        ]);

        $this->log($grant, 'consumed', RuleExceptionStatus::APPROVED, RuleExceptionStatus::CONSUMED, $user, "{$refType}: {$refId}");

        return $grant;
    }

    /** Mark unused grants past their validity as expired. Runs across all entities. */
    public function expireStale(): int
    {
        $count = 0;
        $now = $this->now();

        RuleExceptionRequest::withoutEntityScope()
            ->where('type', 'unlock')
            ->where('status', RuleExceptionStatus::APPROVED->value)
            ->where('valid_until', '<', $now->format('Y-m-d H:i:s'))
            ->pluck('id')
            ->each(function ($id) use (&$count, $now) {
                DB::transaction(function () use ($id, &$count, $now) {
                    $grant = RuleExceptionRequest::withoutEntityScope()->lockForUpdate()->find($id);

                    if (! $grant || $grant->status !== RuleExceptionStatus::APPROVED || $grant->valid_until->gte($now)) {
                        return;
                    }

                    $grant->update(['status' => RuleExceptionStatus::EXPIRED]);
                    $this->log($grant, 'expired', RuleExceptionStatus::APPROVED, RuleExceptionStatus::EXPIRED, null, null);
                    $count++;
                });
            });

        return $count;
    }

    // --- validity ----------------------------------------------------------------

    public function latestValidUntil(array $rule, RuleEvaluator $evaluator, RuleSubject $subject, Carbon $now): Carbon
    {
        $latest = $now->copy()->addHours((int) ($rule['max_validity_hours'] ?? 24));
        $subjectCap = $evaluator->latestValidUntil($subject, $now);

        return $subjectCap && $subjectCap->lt($latest) ? $subjectCap : $latest;
    }

    public function defaultValidUntil(array $rule, Carbon $latest, Carbon $now): Carbon
    {
        $default = $now->copy()->addHours((int) ($rule['default_validity_hours'] ?? 12));

        return $default->lt($latest) ? $default : $latest;
    }

    /** The subject fields a stored request was raised with, for re-resolution. */
    public function subjectInputFrom(RuleExceptionRequest $request): array
    {
        $context = $request->context ?? [];

        return match ($request->rule_key) {
            'mec.upload_window' => ['schedule_id' => $context['schedule_id'] ?? null, 'store_branch_id' => $request->store_branch_id],
            'mass_order.late_order' => ['supplier_code' => $context['supplier_code'] ?? null, 'order_date' => $context['order_date'] ?? null, 'store_branch_id' => $request->store_branch_id],
            'mass_order.edit_after_cutoff' => ['order_number' => $context['order_number'] ?? null],
            'dts_mass_order.late_order' => ['variant' => $context['variant'] ?? null, 'order_date' => $context['order_date'] ?? null, 'store_branch_id' => $request->store_branch_id],
            'dts_mass_order.edit_locked' => ['batch_number' => $context['batch_number'] ?? null],
            default => ['row_key' => $context['row_key'] ?? $request->subject_key],
        };
    }

    // --- internals ---------------------------------------------------------------

    private function validateReason(array $data, ?UploadedFile $attachment): void
    {
        $reasons = array_keys(config('rule_exceptions.reasons', []));
        $min = (int) config('rule_exceptions.justification_min', 20);
        $max = (int) config('rule_exceptions.justification_max', 2000);
        $errors = [];

        if (! in_array($data['reason_code'] ?? null, $reasons, true)) {
            $errors['reason_code'] = 'Choose a reason.';
        }

        $length = mb_strlen(trim((string) ($data['justification'] ?? '')));
        if ($length < $min || $length > $max) {
            $errors['justification'] = "Explain the situation in {$min} to {$max} characters.";
        }

        if (! $attachment && in_array($data['reason_code'] ?? null, config('rule_exceptions.attachment_required_reasons', []), true)) {
            $errors['attachment'] = 'Attach supporting evidence for this reason.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertNoOpenRequest(string $ruleKey, string $subjectKey): void
    {
        $open = RuleExceptionRequest::query()
            ->where('rule_key', $ruleKey)
            ->where('subject_key', $subjectKey)
            ->whereIn('status', RuleExceptionStatus::openValues())
            ->first();

        if ($open) {
            throw ValidationException::withMessages([
                'subject' => $open->status === RuleExceptionStatus::PENDING
                    ? 'An exception request for this is already waiting for approval.'
                    : 'An approved exception for this already exists.',
            ]);
        }
    }

    private function lockPending(RuleExceptionRequest $request): RuleExceptionRequest
    {
        $locked = RuleExceptionRequest::query()->lockForUpdate()->findOrFail($request->id);

        if ($locked->status !== RuleExceptionStatus::PENDING) {
            throw ValidationException::withMessages(['status' => 'This request has already been '.strtolower($locked->status->label()).'.']);
        }

        return $locked;
    }

    private function assertCanDecide(User $user, RuleExceptionRequest $request): void
    {
        if ((int) $request->requested_by === (int) $user->id) {
            throw new HttpException(403, 'You cannot decide your own exception request.');
        }

        if (! $this->canDecide($user, $request)) {
            throw new HttpException(403, 'You are not an approver for this store and module.');
        }
    }

    private function log(RuleExceptionRequest $request, string $action, ?RuleExceptionStatus $from, RuleExceptionStatus $to, ?User $user, ?string $remarks): void
    {
        $http = app()->runningInConsole() ? null : request();

        RuleExceptionRequestAction::create([
            'entity_id' => $request->entity_id,
            'rule_exception_request_id' => $request->id,
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => $user?->id,
            'remarks' => $remarks,
            'snapshot' => [
                'valid_until' => $request->valid_until?->format('Y-m-d H:i:s'),
                'consumed_ref' => $request->consumed_ref_type ? "{$request->consumed_ref_type}:{$request->consumed_ref_id}" : null,
                'subject_key' => $request->subject_key,
            ],
            'ip_address' => $http?->ip(),
            'user_agent' => $http ? mb_substr((string) $http->userAgent(), 0, 512) : null,
        ]);
    }
}
