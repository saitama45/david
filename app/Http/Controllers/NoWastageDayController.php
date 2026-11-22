<?php

namespace App\Http\Controllers;

use App\Http\Services\NoWastageDayService;
use App\Models\NoWastageDay;
use App\Models\UserAssignedStoreBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "No Wastage for the Day" on Create Wastage Record: a store states it had no wastage on a date.
 * Listed for accounting on the Wastage Records page (No Wastage Days).
 */
class NoWastageDayController extends Controller
{
    public function __construct(
        private NoWastageDayService $noWastageDays,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        [$storeBranchId, $date] = $this->storeAndDate($request);

        if ($problem = $this->noWastageDays->problem($storeBranchId, $date)) {
            throw ValidationException::withMessages(['wastage_date' => $problem]);
        }

        $this->noWastageDays->declare($storeBranchId, $date, $request->user());

        return back()->with('success', 'Recorded: no wastage for this store on ' . \Carbon\Carbon::parse($date)->format('M j, Y') . '.');
    }

    /** Undoes a day marked by mistake, so wastage items can be recorded for it. */
    public function unmark(Request $request): RedirectResponse
    {
        [$storeBranchId, $date] = $this->storeAndDate($request);

        if ($problem = $this->noWastageDays->unmarkProblem($storeBranchId, $date)) {
            throw ValidationException::withMessages(['wastage_date' => $problem]);
        }

        $this->noWastageDays->unmark($storeBranchId, $date, $request->user());

        return back()->with('success', 'Unmarked: wastage can now be recorded for this store on ' . \Carbon\Carbon::parse($date)->format('M j, Y') . '.');
    }

    /**
     * The Edit page of one marked day, opened from the No Wastage Days tab: its details, and
     * Unmark while it is still marked. An unmarked day shows who unmarked it and when.
     */
    public function edit(Request $request, NoWastageDay $noWastageDay): Response
    {
        abort_unless($this->isAssigned($request, (int) $noWastageDay->store_branch_id), 403, 'You are not assigned to this store.');

        $noWastageDay->load(['storeBranch:id,name,branch_code', 'declarer:id,first_name,last_name', 'withdrawer:id,first_name,last_name']);
        $date = $noWastageDay->wastage_date->toDateString();
        $name = fn ($user) => trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')) ?: 'Unknown user';
        $marked = $noWastageDay->withdrawn_at === null;

        return Inertia::render('Wastage/NoWastageDayEdit', [
            'day' => [
                'id' => $noWastageDay->id,
                'store_branch_id' => (int) $noWastageDay->store_branch_id,
                'store' => $noWastageDay->storeBranch ? "{$noWastageDay->storeBranch->name} ({$noWastageDay->storeBranch->branch_code})" : 'Unknown Store',
                'wastage_date' => $date,
                'wastage_date_label' => $noWastageDay->wastage_date->format('M j, Y'),
                'declared_by' => $name($noWastageDay->declarer),
                'declared_at' => $noWastageDay->declared_at?->format('M j, Y g:i A'),
                'marked' => $marked,
                'unmarked_by' => $marked ? null : $name($noWastageDay->withdrawer),
                'unmarked_at' => $noWastageDay->withdrawn_at?->format('M j, Y g:i A'),
            ],
            // Why Unmark is not offered for a marked day (a date closed by the month end count).
            'unmarkProblem' => $marked ? $this->noWastageDays->unmarkProblem((int) $noWastageDay->store_branch_id, $date) : null,
        ]);
    }

    /** Whether the store and date picked on Create Wastage Record are marked No Wastage, or can be. */
    public function status(Request $request): JsonResponse
    {
        [$storeBranchId, $date] = $this->storeAndDate($request);

        return response()->json($this->noWastageDays->status($storeBranchId, $date));
    }

    /** @return array{0: int, 1: string} a store assigned to the user, and the date */
    private function storeAndDate(Request $request): array
    {
        $validated = $request->validate([
            'store_branch_id' => ['required', 'integer', 'exists:store_branches,id'],
            'wastage_date' => ['required', 'date_format:Y-m-d'],
        ]);

        if (! $this->isAssigned($request, (int) $validated['store_branch_id'])) {
            throw ValidationException::withMessages(['store_branch_id' => 'You are not assigned to this store.']);
        }

        return [(int) $validated['store_branch_id'], $validated['wastage_date']];
    }

    private function isAssigned(Request $request, int $storeBranchId): bool
    {
        return UserAssignedStoreBranch::where('user_id', $request->user()->id)
            ->where('store_branch_id', $storeBranchId)
            ->exists();
    }
}
