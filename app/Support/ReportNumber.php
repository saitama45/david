<?php

namespace App\Support;

/**
 * How the reports print a number: every quantity, amount and percentage with four
 * decimals whatever its value (5 -> 5.0000), the same on the page
 * (resources/js/lib/reportNumbers.js), in the PDF and in the Excel export. A count of
 * records or a rank stays a whole number.
 */
final class ReportNumber
{
    public const DECIMALS = 4;

    /** Excel number formats: the cell stays a number, only its display is fixed. */
    public const EXCEL = '#,##0.0000';

    public const EXCEL_PESO = '"₱"#,##0.0000';

    public const EXCEL_PERCENT = '#,##0.0000"%"';

    /** The number as text, for a PDF or a note: 1,234.5000. */
    public static function format($value): string
    {
        $number = round((float) $value, self::DECIMALS);

        // A value that rounds to nothing is 0.0000, never -0.0000.
        return number_format($number == 0 ? 0 : $number, self::DECIMALS);
    }
}
