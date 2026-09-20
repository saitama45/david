<?php

namespace App\Services;

use InvalidArgumentException;

/** Pure mapping: no database access and no inventory side effects. */
class PosReceiptMapper
{
    public function number(mixed $value): float
    {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new InvalidArgumentException('Invalid POS numeric value.');
        }
        return (float) $value;
    }

    public function uniqueRows(array $rows, string $key): array
    {
        $unique = [];
        $versions = [];
        foreach ($rows as $row) {
            $id = (string) ($row[$key] ?? '');
            if ($id === '') {
                throw new InvalidArgumentException("Missing source identifier: {$key}.");
            }
            // Source update time is authoritative; arrival time breaks ties.
            $version = (string) ($row['fupdated_date'] ?? $row['fcreated_date'] ?? '')
                .'|'.(string) ($row['_sync_timestamp'] ?? '');
            unset($row['_sync_timestamp']);
            ksort($row);
            if (isset($versions[$id]) && strcmp($version, $versions[$id]) < 0) {
                continue;
            }
            if (isset($versions[$id]) && $version === $versions[$id] && $unique[$id] !== $row) {
                throw new InvalidArgumentException("Conflicting POS copies at the same source version for {$key}={$id}.");
            }
            $versions[$id] = $version;
            $unique[$id] = $row;
        }
        ksort($unique, SORT_STRING);
        return array_values($unique);
    }

    public function map(array $sale, array $lines, array $profile, ?array &$corrections = null): array
    {
        $corrections = [];
        if (($sale['fpost_flag'] ?? '') !== '1' || ($sale['fvoid_flag'] ?? '') !== '0'
            || ($sale['freturn_flag'] ?? '') !== '0' || trim($sale['ftrx_no'] ?? '') === '0') {
            throw new InvalidArgumentException('Unposted, void or return receipt requires separate reconciliation.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Ymd', $sale['fsale_date'] ?? '');
        if (!$date || $date->format('Ymd') !== $sale['fsale_date']) {
            throw new InvalidArgumentException('Invalid POS sale date; expected YYYYMMDD.');
        }
        $receipt = trim($sale['ftrx_no'] ?? '');
        $terminal = trim($sale['ftermid'] ?? '');
        if ($receipt === '' || $receipt === '0' || $terminal === '') {
            throw new InvalidArgumentException('Missing receipt or terminal number.');
        }
        // The legacy Excel report stores the bare receipt and a separate TM#.
        $posted = (string) ($sale['fposted_date'] ?? '');
        if (!preg_match('/^\d{14}$/', $posted)) {
            throw new InvalidArgumentException('Missing complete POS posting timestamp.');
        }
        $subtotal = $this->number($sale['fsubtotal']);
        $netReceipt = $this->number($sale['fgross']) - $this->number($sale['fservice_charge']);
        $lines = $this->uniqueRows($lines, 'fseqno');
        if (!$lines) {
            throw new InvalidArgumentException('Receipt lines have not arrived.');
        }
        $activeTotal = 0.0;
        $detailNet = 0.0;
        $adjustmentsKnown = true;
        foreach ($lines as $line) {
            if (($line['fstatus_flag'] ?? null) !== '1') continue;
            $amount = $this->number($line['ftotal_line']);
            $activeTotal += $amount;
            if (!isset($line['fvar']) || !is_numeric($line['fvar'])) {
                $adjustmentsKnown = false;
                continue;
            }
            $net = $amount * (1 + $this->number($line['fvar']) / 100);
            if ($amount < 0 || $net < 0) $adjustmentsKnown = false;
            $detailNet += $net;
        }
        // Authorized business rule: a zero monetary header does not discard
        // positive active detail sales. Use detail amounts and adjustments.
        $useDetailAmounts = $subtotal == 0.0 && $netReceipt == 0.0
            && $this->number($sale['fgross']) == 0.0 && $activeTotal > 0 && $adjustmentsKnown;
        if ($useDetailAmounts) {
            $subtotal = $activeTotal;
            $netReceipt = $detailNet;
            $corrections[] = sprintf('Zero-value header: imported active detail quantities and amounts; subtotal 0.00 -> %.2f, net 0.00 -> %.2f. Applied detail-authority rule; POS source unchanged.',
                $subtotal, $netReceipt);
        } elseif (abs($activeTotal - $subtotal) > 0.02 && $activeTotal > 0 && $netReceipt >= 0
            && $adjustmentsKnown && abs($detailNet - $netReceipt) <= 0.02) {
            // Correct only the subtotal: independent header net corroborates
            // active detail amounts after their explicit saved adjustments.
            $corrections[] = sprintf('Import subtotal corrected from %.2f to %.2f using active details; detail net %.2f agrees with header net %.2f. POS source unchanged.',
                $subtotal, $activeTotal, $detailNet, $netReceipt);
            $subtotal = $activeTotal;
        }
        if ($subtotal == 0.0 && abs($netReceipt) > 0.01) {
            throw new InvalidArgumentException('Zero merchandise subtotal with nonzero net receipt.');
        }
        $rows = [];
        foreach ($lines as $line) {
            if (!in_array($line['fstatus_flag'], ['0', '1', '8', '9', 'V', 'W'], true)) {
                throw new InvalidArgumentException('Unmapped POS line status.');
            }
            if ($line['fstatus_flag'] !== '1') {
                continue; // Removed/void/waiting lines are not current sold items.
            }
            if (!in_array($line['fdeliver_flag'] ?? null, ['0', '1'], true)) {
                throw new InvalidArgumentException('Unmapped POS line take-out flag.');
            }
            $qty = $this->number($line['fqty']);
            $base = $this->number($line['fuomqty']);
            $base = $qty * $base;
            $price = $this->number($line['funitprice']);
            $lineTotal = $this->number($line['ftotal_line']);
            $lineVariance = $this->number($line['fvar'] ?? 0);
            // The existing destination columns are integers. Never truncate sales.
            if ($qty <= 0 || $base <= 0 || $qty > 2147483647 || $base > 2147483647 || floor($qty) !== $qty || floor($base) !== $base) {
                throw new InvalidArgumentException('Nonpositive or fractional quantity cannot be imported into the current integer quantity columns.');
            }
            if (trim($line['fproductid']) === '') {
                throw new InvalidArgumentException('Missing POS product code.');
            }
            $rows[] = [
                '__row_number' => $line['fseqno'], '__branch_id' => $profile['branch_id'],
                'branch' => $profile['site'], 'receipt_no' => $receipt,
                'date' => $date->format('Y-m-d'), 'posted' => substr($posted, 8, 4), 'tm' => $terminal,
                'product_id' => trim($line['fproductid']), 'product_name' => trim($line['fproductid']),
                'uom' => $line['fuom'], 'qty' => $qty, 'base_qty' => $base,
                'price' => $price,
                'discount' => round($price * $qty - $lineTotal, 2),
                'line_total' => $lineTotal,
                'net_total' => round($useDetailAmounts || $lineVariance != 0.0
                    ? $lineTotal * (1 + $lineVariance / 100)
                    : ($subtotal == 0.0 ? $lineTotal : $lineTotal * $netReceipt / $subtotal), 2),
                'take_out' => $line['fdeliver_flag'] === '1' ? 'Y' : '',
            ];
        }
        if (!$rows) {
            throw new InvalidArgumentException('Receipt has no active sold lines.');
        }
        // Do not replace conflicting header and detail amounts without corroboration.
        if (abs(array_sum(array_column($rows, 'line_total')) - $subtotal) > 0.02) {
            throw new InvalidArgumentException(sprintf(
                'Receipt totals conflict: header subtotal %.2f, active detail total %.2f, header net %.2f, detail net %s. Correct amount is not corroborated; reconcile source details before importing.',
                $subtotal, $activeTotal, $netReceipt, $adjustmentsKnown ? number_format($detailNet, 2, '.', '') : 'unavailable'));
        }
        return $rows;
    }
}
