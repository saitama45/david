<?php

namespace App\Jobs;

use App\Http\Services\NotificationMailService;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Emails each store the lines of its order that CS Mass Commits' Confirm All committed at a
 * quantity other than the one ordered. Dispatched after the response, so the CS user does not wait
 * for the mail server and no queue worker is needed; a mail failure is only logged.
 */
class SendCommitVarianceEmails
{
    use Dispatchable;

    /**
     * @param  array<int, list<int>>  $lineIdsByOrder  order id => ids of the lines committed in the run
     */
    public function __construct(public array $lineIdsByOrder, public ?int $committedById = null) {}

    public function handle(NotificationMailService $mail): void
    {
        $committedBy = $this->committedById ? User::find($this->committedById) : null;

        foreach ($this->lineIdsByOrder as $orderId => $lineIds) {
            $order = StoreOrder::with(['store_branch', 'supplier'])->find($orderId);
            if (! $order) {
                continue;
            }

            // Read again: the quantity may have changed since the commit was confirmed.
            $lines = StoreOrderItem::whereIn('id', $lineIds)->get()
                ->filter(fn (StoreOrderItem $line) => NotificationMailService::needsVarianceNotice($line))
                ->values();

            if ($lines->isNotEmpty() && $mail->sendCommitVariance($order, $lines, $committedBy)) {
                foreach ($lines as $line) {
                    // A query update: no audit row for a delivery note of the system's own.
                    StoreOrderItem::whereKey($line->id)->update([
                        'commit_variance_notified_qty' => number_format(round((float) $line->quantity_commited, 4), 4, '.', ''),
                    ]);
                }
            }
        }
    }
}
