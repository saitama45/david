<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

class StoreReceiptIdentity
{
    public static function normalize(string $value): string
    {
        $value = strtoupper(trim($value));
        return ctype_digit($value) ? (ltrim($value, '0') ?: '0') : $value;
    }

    public static function parts($receipt, $terminal): array
    {
        $receipt = strtoupper(trim((string) $receipt));
        $terminal = self::normalize((string) $terminal);
        if (preg_match('/^([^-]+)-([0-9]+)$/', $receipt, $match)) {
            $prefix = self::normalize($match[1]);
            if ($terminal !== '' && $prefix !== $terminal) {
                throw new InvalidArgumentException('Receipt prefix does not match the terminal number.');
            }
            $terminal = $prefix;
            $receipt = $match[2];
        }
        $receipt = self::normalize($receipt);
        if ($terminal === '' || $receipt === '' || $receipt === '0') {
            throw new InvalidArgumentException('A terminal and nonzero receipt number are required.');
        }
        return [$terminal, $receipt];
    }

    public static function date($value): string
    {
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
        if (is_numeric($value)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }
        $value = trim((string) $value);
        foreach (['Y-m-d', 'm/d/Y', 'n/j/Y', 'n/d/Y', 'm/j/Y', 'Y-m-d H:i:s'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
                if ($date && $date->format($format) === $value) return $date->format('Y-m-d');
            } catch (\Throwable) {}
        }
        throw new InvalidArgumentException('Invalid business date. Use YYYY-MM-DD, MM/DD/YYYY or an Excel date.');
    }

    public static function key(int $entity, int $branch, $date, $receipt, $terminal): string
    {
        return hash('sha256', json_encode([$entity, $branch, self::date($date), ...self::parts($receipt, $terminal)]));
    }

    public static function terminals(string $terminal): array
    {
        $terminal = self::normalize($terminal);
        return ctype_digit($terminal) ? [$terminal, str_pad($terminal, 4, '0', STR_PAD_LEFT)] : [$terminal];
    }

    public static function aliases(string $receipt, string $terminal): array
    {
        [$terminal, $bare] = self::parts($receipt, $terminal);
        $values = [$receipt, $bare, str_pad($bare, 8, '0', STR_PAD_LEFT)];
        foreach (self::terminals($terminal) as $tm) {
            $values[] = $tm.'-'.$bare;
            $values[] = $tm.'-'.str_pad($bare, 8, '0', STR_PAD_LEFT);
        }
        return array_unique($values);
    }
}
