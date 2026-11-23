<?php

namespace App\Mail;

use App\Models\StoreBranch;
use App\Models\User;
use App\Models\Wastage;
use Carbon\Carbon;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * Tells the store's Wastage Approval Email (Store Branches) that a wastage of the store got its
 * final approval, with what was approved and its cost. Sent from the no-reply notification mailbox.
 */
class WastageApprovedMail extends Mailable
{
    /** @var list<array{item_code: string, item_name: string, uom: string, quantity: float, unit_cost: float, total_cost: float, reason: string}> */
    public array $rows;

    public float $totalCost;

    /**
     * @param  Collection<int, Wastage>  $lines  the approved lines of one wastage
     */
    public function __construct(public StoreBranch $branch, public Collection $lines, public ?User $approver = null)
    {
        $this->rows = $lines->map(function (Wastage $line) {
            $item = $line->lineItem();
            $quantity = round((float) ($line->approverlvl2_qty ?? $line->approverlvl1_qty ?? $line->wastage_qty), 4);

            return [
                'item_code' => (string) ($item['ItemCode'] ?? ''),
                'item_name' => (string) ($item['ItemDescription'] ?? ''),
                'uom' => (string) ($item['AltUOM'] ?? ''),
                'quantity' => $quantity,
                'unit_cost' => round((float) $line->cost, 4),
                'total_cost' => round($quantity * (float) $line->cost, 4),
                'reason' => (string) $line->reason,
            ];
        })->values()->all();

        $this->totalCost = round(array_sum(array_column($this->rows, 'total_cost')), 4);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[No-Reply] Wastage {$this->lines->first()->wastage_no} approved - {$this->branch->name}",
        );
    }

    public function content(): Content
    {
        $first = $this->lines->first();
        $approvedBy = $this->approver ? $this->fullName($this->approver) : null;
        $level1By = $first->approver1 ? $this->fullName($first->approver1) : null;

        return new Content(
            view: 'emails.wastage-approved',
            with: [
                'wastageNo' => $first->wastage_no,
                'rows' => $this->rows,
                'totalCost' => $this->totalCost,
                'storeName' => $this->branch->name,
                'entityName' => $this->branch->entity?->name,
                'wastageDate' => $first->wastage_date ? Carbon::parse($first->wastage_date)->format('M j, Y') : null,
                'filedBy' => $first->encoder ? $this->fullName($first->encoder) : null,
                // Two-level approval only: who approved before the final approver.
                'level1By' => $level1By !== null && $level1By !== $approvedBy ? $level1By : null,
                'approvedBy' => $approvedBy,
                'approvedAt' => ($first->approved_level2_date ?? now())->copy()->timezone('Asia/Manila')->format('M j, Y g:i A'),
                'remarks' => $this->lines->pluck('remarks')->map(fn ($r) => trim((string) $r))->filter()->unique()->implode(' / '),
            ],
        );
    }

    private function fullName(User $user): string
    {
        return trim($user->first_name.' '.$user->last_name);
    }
}
