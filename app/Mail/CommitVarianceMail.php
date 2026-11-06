<?php

namespace App\Mail;

use App\Models\StoreOrder;
use App\Models\SupplierItems;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * Tells a store which lines of its order CS committed at a quantity other than the one it ordered,
 * so it knows before the delivery arrives. Sent from the no-reply notification mailbox.
 */
class CommitVarianceMail extends Mailable
{
    /** @var list<array{item_code: string, item_name: string, uom: ?string, ordered: float, committed: float, variance: float}> */
    public array $rows;

    /**
     * @param  Collection<int, \App\Models\StoreOrderItem>  $lines  the lines with a variance
     */
    public function __construct(public StoreOrder $order, Collection $lines, public ?User $committedBy = null)
    {
        $names = SupplierItems::whereIn('ItemCode', $lines->pluck('item_code')->filter()->unique()->values()->all() ?: ['__none__'])
            ->pluck('item_name', 'ItemCode');

        $this->rows = $lines->map(fn ($line) => [
            'item_code' => (string) $line->item_code,
            'item_name' => (string) ($names[$line->item_code] ?? $line->item_description ?? ''),
            'uom' => $line->uom,
            'ordered' => round((float) $line->quantity_ordered, 4),
            'committed' => round((float) $line->quantity_commited, 4),
            'variance' => round((float) $line->quantity_commited - (float) $line->quantity_ordered, 4),
        ])->values()->all();
    }

    public function envelope(): Envelope
    {
        $store = $this->order->store_branch?->name ?? 'your store';

        return new Envelope(
            subject: "[No-Reply] Commit variance on order {$this->order->order_number} - {$store}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.commit-variance',
            with: [
                'order' => $this->order,
                'rows' => $this->rows,
                'storeName' => $this->order->store_branch?->name,
                'template' => $this->order->supplier?->name ?? $this->order->supplier?->supplier_code,
                'deliveryDate' => $this->order->order_date ? Carbon::parse($this->order->order_date)->format('M j, Y') : null,
                'committedBy' => $this->committedBy ? trim($this->committedBy->first_name.' '.$this->committedBy->last_name) : null,
                'committedAt' => now('Asia/Manila')->format('M j, Y g:i A'),
            ],
        );
    }
}
