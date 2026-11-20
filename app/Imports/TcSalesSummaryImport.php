<?php

namespace App\Imports;

use App\Models\StoreBranch;
use App\Services\StoreTransactionReceiptProcessor;
use App\Support\StoreReceiptIdentity;
use Exception;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * The TC entity's manual sales upload: a daily sales summary with one row per store, date and
 * item (Store Code | Date | Item Code | Item Name | Actual Item Name | Qty | Net Sales).
 *
 * The file carries no receipts, so each store and date posts as one sale (terminal DAILY, receipt
 * YYYYMMDD) through the shared receipt processor: a day's items post together or not at all, and
 * the same store and date cannot be posted twice. Item Code is the POS Code; the two names are
 * for the reader only. Net Sales is the line's net (and line) total, and the price is the average
 * Net Sales / Qty. Store Code is the store's location code or branch code within the entity.
 */
class TcSalesSummaryImport extends StoreTransactionReceiptProcessor implements ToCollection
{
    public const TERMINAL = 'DAILY';

    private const HEADER_ALIASES = [
        'store code' => 'branch',
        'branch code' => 'branch',
        'date' => 'date',
        'sales date' => 'date',
        'item code' => 'product_id',
        'pos code' => 'product_id',
        'item name' => 'product_name',
        'actual item name' => 'actual_item_name',
        'qty' => 'qty',
        'quantity' => 'qty',
        'net sales' => 'net_total',
    ];

    private const REQUIRED = [
        'branch' => 'Store Code', 'date' => 'Date', 'product_id' => 'Item Code', 'qty' => 'Qty', 'net_total' => 'Net Sales',
    ];

    /** @var array<string, Collection> Store Code => matching stores of the active entity */
    private array $branches = [];

    public function collection(Collection $rows)
    {
        [$headerIndex, $headerMap] = $this->detectHeader($rows);

        if ($headerMap === null) {
            throw new Exception('Could not find the header row of the TC sales file. Expected the columns '
                .implode(', ', self::REQUIRED).'.');
        }

        $days = [];
        $invalidDate = false;

        foreach ($rows as $index => $rawRow) {
            if ($index <= $headerIndex) {
                continue;
            }

            $row = ['__row_number' => $index + 1];
            foreach ($headerMap as $column => $key) {
                $row[$key] = $rawRow[$column] ?? null;
            }

            $row['product_id'] = strtoupper(trim((string) ($row['product_id'] ?? '')));
            $row['branch'] = strtoupper(trim((string) ($row['branch'] ?? '')));

            // Blank lines and total lines carry no Item Code.
            if ($row['product_id'] === '') {
                continue;
            }

            $row['qty'] = $this->number($row['qty'] ?? null);
            $row['net_total'] = $this->number($row['net_total'] ?? null);

            // An item listed with nothing sold is not a sale.
            if ($row['qty'] === 0 && $row['net_total'] === 0) {
                continue;
            }

            try {
                $row['date'] = $this->date($row['date'] ?? null);
            } catch (InvalidArgumentException $e) {
                $invalidDate = true;
                $this->addSkippedGroup(collect([$row]), $e->getMessage());

                continue;
            }

            $qty = $row['qty'];
            $net = $row['net_total'];
            $row['tm'] = self::TERMINAL;
            $row['receipt_no'] = str_replace('-', '', $row['date']);
            $row['posted'] = '';
            $row['take_out'] = '';
            $row['uom'] = '';
            $row['base_qty'] = $qty;
            $row['discount'] = 0;
            $row['line_total'] = $net;
            $row['price'] = is_numeric($qty) && is_numeric($net) && $qty != 0 ? round(abs($net / $qty), 4) : 0;

            // Grouped by the store found, so GL1 and its branch code on one date are still one day.
            $stores = $this->storesFor($row['branch']);
            if ($stores->count() === 1) {
                $row['__branch_id'] = $stores->first()->id;
            }

            $days[json_encode([$row['__branch_id'] ?? 'code:'.$row['branch'], $row['date']])][] = $row;
        }

        foreach ($days as $dayRows) {
            $dayRows = collect($dayRows);

            // A row whose date cannot be read could belong to any day: post nothing rather than an incomplete day.
            if ($invalidDate) {
                $this->addSkippedGroup($dayRows, 'The file has a row with an invalid date. Correct the file and upload it again; nothing was posted.');

                continue;
            }

            if (!isset($dayRows->first()['__branch_id'])) {
                $code = $dayRows->first()['branch'];
                $this->addSkippedGroup($dayRows, match (true) {
                    $code === '' => 'Store Code is blank.',
                    $this->storesFor($code)->isEmpty() => "Store Code {$code} is not a store of this entity.",
                    default => "Store Code {$code} matches more than one store of this entity.",
                });

                continue;
            }

            $this->processReceiptGroup($dayRows);
        }
    }

    protected function addSkippedGroup(Collection $rows, string $reason): void
    {
        if (str_starts_with($reason, 'Receipt already exists')) {
            $reason = 'Sales of this store for this date were already uploaded; nothing was posted again.';
        }

        parent::addSkippedGroup($rows, $reason);
    }

    /** Locate the header row and build a [column index => internal key] map; [-1, null] when absent. */
    private function detectHeader(Collection $rows): array
    {
        foreach ($rows as $index => $row) {
            if ($index > 30) {
                break;
            }

            $map = [];
            foreach ($row as $column => $value) {
                if (!is_string($value)) {
                    continue;
                }

                $key = self::HEADER_ALIASES[strtolower(trim(preg_replace('/\s+/', ' ', $value)))] ?? null;

                if ($key !== null && !in_array($key, $map, true)) {
                    $map[$column] = $key;
                }
            }

            if (count(array_intersect(array_keys(self::REQUIRED), $map)) === count(self::REQUIRED)) {
                return [$index, $map];
            }
        }

        return [-1, null];
    }

    private function storesFor(string $code): Collection
    {
        if ($code === '') {
            return collect();
        }

        return $this->branches[$code] ??= StoreBranch::query()
            ->where(fn ($query) => $query->whereRaw('UPPER(location_code) = ?', [$code])
                ->orWhereRaw('UPPER(branch_code) = ?', [$code]))
            ->get(['id']);
    }

    /**
     * A cell as a number: numbers pass, text drops thousands separators and reads "(5)" as -5,
     * and a blank or "-" (the accounting format's zero) is 0. Anything else is returned as it
     * was, for the processor to refuse.
     */
    private function number($value)
    {
        if (is_int($value) || is_float($value)) {
            return $value == 0 ? 0 : $value;
        }

        $text = str_replace([',', ' ', "\u{00A0}"], '', trim((string) $value));

        if ($text === '' || $text === '-') {
            return 0;
        }

        if (preg_match('/^\((\d+(?:\.\d+)?)\)$/', $text, $match)) {
            $text = '-'.$match[1];
        }

        if (!is_numeric($text)) {
            return $value;
        }

        $number = $text + 0;

        return $number == 0 ? 0 : $number;
    }

    /** The sales date as Y-m-d; also takes the template's two-digit year (11/20/26). */
    private function date($value): string
    {
        if (is_string($value) && preg_match('#^\s*(\d{1,2})/(\d{1,2})/(\d{2})\s*$#', $value, $match)) {
            [$month, $day, $year] = [(int) $match[1], (int) $match[2], 2000 + (int) $match[3]];

            if (!checkdate($month, $day, $year)) {
                throw new InvalidArgumentException('Invalid sales date. Use MM/DD/YYYY or an Excel date.');
            }

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return StoreReceiptIdentity::date($value);
    }
}
