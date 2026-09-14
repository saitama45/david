<?php

namespace App\Http\Controllers;

use App\Enums\RuleExceptionStatus;
use App\Exports\RuleExceptionLogExport;
use App\Http\Services\RuleExceptionService;
use App\Models\RuleExceptionRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Business-rule exception requests: the approver queue, the requester's own
 * requests, the audit log, and the request / decide / cancel endpoints.
 * Every authorization decision is made in RuleExceptionService.
 */
class RuleExceptionController extends Controller
{
    public function __construct(private RuleExceptionService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $canViewLog = $user->can('view rule exception log');
        $canApprove = collect($this->service->rules())->contains(fn ($rule) => $user->can($rule['approve_permission']));

        $tab = $request->input('tab', $canApprove ? 'approvals' : 'mine');
        if (($tab === 'log' && ! $canViewLog) || ($tab === 'approvals' && ! $canApprove)) {
            $tab = 'mine';
        }

        $query = match ($tab) {
            'approvals' => $this->service->decidableQuery($user),
            'log' => RuleExceptionRequest::query(),
            default => RuleExceptionRequest::query()->where('requested_by', $user->id),
        };

        $this->applyFilters($query, $request);

        $requests = $query->with(['storeBranch:id,name', 'requester:id,first_name,last_name', 'decider:id,first_name,last_name'])
            ->orderByDesc('requested_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (RuleExceptionRequest $row) => $this->present($row));

        return Inertia::render('RuleExceptions/Index', [
            'requests' => $requests,
            'tab' => $tab,
            'canApprove' => $canApprove,
            'canViewLog' => $canViewLog,
            'filters' => $request->only(['status', 'module', 'store_branch_id', 'date_from', 'date_to']),
            'modules' => collect($this->service->rules())->pluck('module')->unique()->values(),
            'statuses' => collect(RuleExceptionStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'reasons' => config('rule_exceptions.reasons'),
        ]);
    }

    /** Excel export of the full exception log with the current filters. */
    public function export(Request $request)
    {
        $query = $this->applyFilters(RuleExceptionRequest::query(), $request);

        return Excel::download(new RuleExceptionLogExport($query), 'rule-exception-log-'.now('Asia/Manila')->format('Y-m-d').'.xlsx');
    }

    public function show(Request $request, RuleExceptionRequest $ruleException)
    {
        $this->authorizeView($request, $ruleException);
        $ruleException->load(['actions.user:id,first_name,last_name', 'storeBranch:id,name', 'requester:id,first_name,last_name', 'decider:id,first_name,last_name', 'consumer:id,first_name,last_name']);

        $user = $request->user();
        $payload = $this->present($ruleException) + [
            'justification' => $ruleException->justification,
            'decision_remarks' => $ruleException->decision_remarks,
            'consumed_by_name' => $this->name($ruleException->consumer),
            'consumed_at' => $ruleException->consumed_at?->format('Y-m-d H:i:s'),
            'consumed_ref' => $ruleException->consumed_ref_type ? "{$ruleException->consumed_ref_type}: {$ruleException->consumed_ref_id}" : null,
            'has_attachment' => (bool) $ruleException->attachment_path,
            'can_decide' => $ruleException->status === RuleExceptionStatus::PENDING && $this->service->canDecide($user, $ruleException),
            'can_cancel' => $ruleException->status === RuleExceptionStatus::PENDING && (int) $ruleException->requested_by === (int) $user->id,
            'actions' => $ruleException->actions->map(fn ($action) => [
                'action' => $action->action,
                'to_status' => $action->to_status,
                'user_name' => $this->name($action->user) ?? 'System',
                'remarks' => $action->remarks,
                'created_at' => $action->created_at?->format('Y-m-d H:i:s'),
            ]),
        ];

        if ($payload['can_decide'] && $ruleException->isUnlock()) {
            $payload['validity'] = $this->validityWindow($ruleException);
        }

        return response()->json($payload);
    }

    /**
     * What the UI may offer for a blocked action: whether the user can request
     * an exception, or the open request / usable grant that already exists.
     */
    public function eligibility(Request $request)
    {
        $ruleKey = (string) $request->input('rule_key');
        $rule = $this->service->rule($ruleKey);
        $evaluator = $this->service->evaluator($ruleKey);
        $input = $request->validate($evaluator->inputRules());

        try {
            $subject = $evaluator->resolve($input, $request->user());
        } catch (ValidationException $e) {
            return response()->json(['can_request' => false, 'reason' => collect($e->errors())->flatten()->first()]);
        }

        $open = RuleExceptionRequest::query()
            ->where('rule_key', $ruleKey)
            ->where('subject_key', $subject->subjectKey)
            ->whereIn('status', RuleExceptionStatus::openValues())
            ->first();

        if ($open) {
            return response()->json([
                'can_request' => false,
                'open_request' => $this->present($open),
                'reason' => $open->status === RuleExceptionStatus::PENDING
                    ? 'An exception request is waiting for approval.'
                    : 'An approved exception is ready to use until '.$open->valid_until?->format('M j, Y g:i A').'.',
            ]);
        }

        if (! $this->service->canRequest($request->user(), $ruleKey, $subject)) {
            return response()->json(['can_request' => false, 'reason' => 'You are not allowed to request this exception for this store.']);
        }

        $notNeeded = $evaluator->notNeededReason($subject, $request->user(), $this->service->now());

        return response()->json([
            'can_request' => $notNeeded === null,
            'reason' => $notNeeded,
            'rule' => ['key' => $ruleKey, 'label' => $rule['label'], 'type' => $rule['type']],
            'subject' => $subject->context,
            'reasons' => config('rule_exceptions.reasons'),
            'attachment_required_reasons' => config('rule_exceptions.attachment_required_reasons'),
            'justification_min' => (int) config('rule_exceptions.justification_min'),
        ]);
    }

    public function store(Request $request)
    {
        $ruleKey = (string) $request->input('rule_key');
        $evaluator = $this->service->evaluator($ruleKey);

        $validated = $request->validate([
            'rule_key' => ['required', 'string'],
            'reason_code' => ['required', 'string'],
            'justification' => ['required', 'string'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:'.(int) config('rule_exceptions.attachment_max_kb', 5120)],
        ] + $evaluator->inputRules());

        $exception = $this->service->submit(
            $request->user(),
            $validated,
            collect($validated)->only(array_keys($evaluator->inputRules()))->all(),
            $request->file('attachment')
        );

        return back()->with('success', "Exception request #{$exception->id} submitted for approval.");
    }

    public function approve(Request $request, RuleExceptionRequest $ruleException)
    {
        $validated = $request->validate([
            'valid_until' => ['nullable', 'date'],
            'decision_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->service->approve($request->user(), $ruleException, $validated['valid_until'] ?? null, $validated['decision_remarks'] ?? null);

        return back()->with('success', "Exception request #{$ruleException->id} approved.");
    }

    public function reject(Request $request, RuleExceptionRequest $ruleException)
    {
        $validated = $request->validate(['decision_remarks' => ['required', 'string', 'max:2000']]);

        $this->service->reject($request->user(), $ruleException, $validated['decision_remarks']);

        return back()->with('success', "Exception request #{$ruleException->id} rejected.");
    }

    public function cancel(Request $request, RuleExceptionRequest $ruleException)
    {
        $this->service->cancel($request->user(), $ruleException);

        return back()->with('success', "Exception request #{$ruleException->id} cancelled.");
    }

    public function attachment(Request $request, RuleExceptionRequest $ruleException)
    {
        $this->authorizeView($request, $ruleException);

        if (! $ruleException->attachment_path || ! Storage::disk('local')->exists($ruleException->attachment_path)) {
            abort(404);
        }

        return Storage::disk('local')->download($ruleException->attachment_path);
    }

    // --- helpers -------------------------------------------------------------

    private function applyFilters($query, Request $request)
    {
        return $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('module'), fn ($q) => $q->where('module', $request->input('module')))
            ->when($request->filled('store_branch_id'), fn ($q) => $q->where('store_branch_id', $request->input('store_branch_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('requested_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('requested_at', '<=', $request->input('date_to')));
    }

    /** Requester, anyone who may decide it (or already did), and log viewers. */
    private function authorizeView(Request $request, RuleExceptionRequest $ruleException): void
    {
        $user = $request->user();
        $rule = $this->service->rules()[$ruleException->rule_key] ?? null;

        $allowed = (int) $ruleException->requested_by === (int) $user->id
            || (int) $ruleException->decided_by === (int) $user->id
            || $user->can('view rule exception log')
            || ($rule && $user->can($rule['approve_permission'])
                && $this->service->isAssignedToStores($user, $ruleException->context['store_ids'] ?? [$ruleException->store_branch_id]));

        if (! $allowed) {
            throw new HttpException(403, 'You cannot view this exception request.');
        }
    }

    private function validityWindow(RuleExceptionRequest $ruleException): ?array
    {
        try {
            $rule = $this->service->rule($ruleException->rule_key);
            $evaluator = $this->service->evaluator($ruleException->rule_key);
            $subject = $evaluator->resolve($this->service->subjectInputFrom($ruleException), $ruleException->requester);
            $now = $this->service->now();
            $latest = $this->service->latestValidUntil($rule, $evaluator, $subject, $now);

            return [
                'default' => $this->service->defaultValidUntil($rule, $latest, $now)->format('Y-m-d\TH:i'),
                'latest' => $latest->format('Y-m-d\TH:i'),
            ];
        } catch (ValidationException $e) {
            return ['error' => collect($e->errors())->flatten()->first()];
        }
    }

    private function present(RuleExceptionRequest $row): array
    {
        $context = $row->context ?? [];

        return [
            'id' => $row->id,
            'rule_key' => $row->rule_key,
            'rule_label' => $context['rule_label'] ?? $row->rule_key,
            'module' => $row->module,
            'type' => $row->type,
            'summary' => $context['summary'] ?? $row->subject_key,
            'store_name' => $row->storeBranch?->name ?? ($context['store_name'] ?? null),
            'reason_code' => $row->reason_code,
            'reason_label' => config("rule_exceptions.reasons.{$row->reason_code}", $row->reason_code),
            'status' => $row->status->value,
            'status_label' => $row->status->label(),
            'requested_by_name' => $this->name($row->requester),
            'requested_at' => $row->requested_at?->format('Y-m-d H:i:s'),
            'decided_by_name' => $this->name($row->decider),
            'decided_at' => $row->decided_at?->format('Y-m-d H:i:s'),
            'valid_until' => $row->valid_until?->format('Y-m-d H:i:s'),
        ];
    }

    private function name($user): ?string
    {
        return $user ? trim("{$user->first_name} {$user->last_name}") : null;
    }
}
