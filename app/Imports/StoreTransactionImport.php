<?php

namespace App\Imports;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;

class StoreTransactionImport extends \App\Services\StoreTransactionReceiptProcessor implements ToCollection
{
    /**
     * Maps a normalised header label (lower-cased, whitespace collapsed) to the
     * internal key the importer works with. Covers both the legacy Sales Audit
     * layout and the new HQ "Sales Report by Product" layout, so a file in either
     * format can be uploaded without the user picking a template.
     *
     * Notable new-format differences:
     *  - "TM-OR#" replaces the separate "TM#" / "Receipt No" pair. The TM number
     *    is the segment before the dash, so it is recovered in resolveTimNumber().
     *  - "% Discount" is a new column that would otherwise collapse onto the same
     *    key as "Discount", so it gets its own key and is ignored.
     *  - "Take Out" is new: "Y" = take out, blank = dine in.
     */
    private const HEADER_ALIASES = [
        'product id' => 'product_id',
        'productid' => 'product_id',
        'product name' => 'product_name',
        'lot/serial' => 'lot_serial',
        'lot serial' => 'lot_serial',
        'date' => 'date',
        'posted' => 'posted',
        'tm#' => 'tm',
        'tm no' => 'tm',
        'tm no.' => 'tm',
        'receipt no' => 'receipt_no',
        'receipt no.' => 'receipt_no',
        'receipt number' => 'receipt_no',
        'tm-or#' => 'receipt_no',
        'tm-or #' => 'receipt_no',
        'tm or#' => 'receipt_no',
        'tm-or' => 'receipt_no',
        'qty' => 'qty',
        'base qty' => 'base_qty',
        'unit' => 'uom',
        'uom' => 'uom',
        'price' => 'price',
        'discount' => 'discount',
        '% discount' => 'discount_percent',
        'discount %' => 'discount_percent',
        'line total' => 'line_total',
        'net total' => 'net_total',
        'price id' => 'price_id',
        'take out' => 'take_out',
        'take-out' => 'take_out',
        'takeout' => 'take_out',
        'branch' => 'branch',
        'customer id' => 'customer_id',
        'customer' => 'customer',
        'cust name' => 'customer',
        'cancel reason' => 'cancel_reason',
        'reference number' => 'reference_number',
        'reference' => 'reference_number',
    ];

    private $rowNumber = 0;

    public function collection(Collection $rows)
    {
        // The header no longer sits on a fixed row: the legacy export put it on
        // row 6, the new HQ report puts it higher up. Find it by content instead.
        [$headerIndex, $headerMap] = $this->detectHeader($rows);

        if ($headerMap === null) {
            throw new Exception('Could not find the header row. Expected a row containing "Product ID" and "Branch".');
        }

        // Pre-process rows to add row numbers and filter invalid ones
        $validRows = [];

        foreach ($rows as $index => $rawRow) {
            if ($index <= $headerIndex) {
                continue;
            }

            $this->rowNumber = $index + 1; // Collection is 0-based, spreadsheet rows are 1-based

            // Check for "NOTHING FOLLOWS"
            foreach ($rawRow as $value) {
                if (is_string($value) && stripos($value, 'NOTHING FOLLOWS') !== false) {
                    Log::info('Found "NOTHING FOLLOWS". Ending import.', ['row_number' => $this->rowNumber]);
                    break 2; // Stop processing entirely
                }
            }

            $row = $this->mapRow($rawRow, $headerMap);

            // Check for SUBTOTAL. The legacy file carries it in the Product Name
            // column, the new one in the TM-OR# (receipt) column.
            if ($this->isSubtotal($row['product_name'] ?? null) || $this->isSubtotal($row['receipt_no'] ?? null)) {
                continue;
            }

            // Skip if essential data is missing. A line only counts as a sale
            // (dine in or take out) when it carries a Product ID.
            if (empty($row['product_id'])) {
                continue;
            }

            // Attach the original row number for tracking
            $row['__row_number'] = $this->rowNumber;
            $validRows[] = $row;
        }

        $groupedByReceipt = collect();
        $invalidIdentity = false;
        foreach ($validRows as $row) {
            try {
                $row['date'] = \App\Support\StoreReceiptIdentity::date($row['date'] ?? null);
                [$row['tm'], $row['receipt_no']] = \App\Support\StoreReceiptIdentity::parts($row['receipt_no'] ?? '', $row['tm'] ?? '');
                $row['branch'] = strtoupper(trim($row['branch'] ?? ''));
                $key = json_encode([$row['branch'], $row['date'], $row['tm'], $row['receipt_no']]);
                if (!$groupedByReceipt->has($key)) $groupedByReceipt->put($key, collect());
                $groupedByReceipt->get($key)->push($row);
            } catch (\InvalidArgumentException $e) {
                $invalidIdentity = true;
                $this->addSkippedGroup(collect([$row]), $e->getMessage());
            }
        }

        foreach ($groupedByReceipt as $key => $receiptRows) {
            if ($invalidIdentity) {
                $this->addSkippedGroup($receiptRows, 'File contains an invalid receipt identity or date. Correct the file before posting to avoid incomplete receipts.');
                continue;
            }
            $this->processReceiptGroup($receiptRows);
        }
    }

    /**
     * Locate the header row and build a [column index => internal key] map.
     * Returns [rowIndex, map] or [-1, null] when no header could be found.
     */
    private function detectHeader(Collection $rows): array
    {
        foreach ($rows as $index => $row) {
            $map = [];

            foreach ($row as $column => $value) {
                $key = $this->normalizeHeaderLabel($value);

                // First occurrence wins so a stray repeated label cannot shadow
                // the real column.
                if ($key !== null && !in_array($key, $map, true)) {
                    $map[$column] = $key;
                }
            }

            if (in_array('product_id', $map, true) && in_array('branch', $map, true)) {
                return [$index, $map];
            }

            // Give up after the preamble rows; the header is always near the top.
            if ($index >= 30) {
                break;
            }
        }

        return [-1, null];
    }

    private function normalizeHeaderLabel($value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        // Header cells in the new report wrap ("Net\n Total"), so collapse all
        // whitespace before matching.
        $label = strtolower(trim(preg_replace('/\s+/', ' ', (string) $value)));

        if ($label === '') {
            return null;
        }

        if (isset(self::HEADER_ALIASES[$label])) {
            return self::HEADER_ALIASES[$label];
        }

        $slug = Str::slug($label, '_');

        return $slug === '' ? null : $slug;
    }

    private function mapRow($rawRow, array $headerMap): array
    {
        $row = [];

        foreach ($headerMap as $column => $key) {
            $row[$key] = $rawRow[$column] ?? null;
        }

        return $row;
    }

    private function isSubtotal($value): bool
    {
        return is_string($value) && stripos($value, 'SUBTOTAL') !== false;
    }

}
