<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * The BOM Change History as Excel: one row per field changed, so a change of three fields is
 * three rows sharing its date, user and line. Values are the text the history recorded
 * (WithStrictNullComparison keeps a 0 as 0).
 */
class PosRecipeChangesExport implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison, WithTitle
{
    /** @param  array<int, array<string, mixed>>  $changes  as PosRecipeHistoryService::present() gives them */
    public function __construct(private array $changes) {}

    public function headings(): array
    {
        return ['Date Changed', 'Changed By', 'Type', 'POS Code', 'Item Code', 'BOM UOM', 'Assembly', 'Action', 'Field', 'Old Value', 'New Value', 'Source', 'File'];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->changes as $change) {
            $fields = $change['changes'] ?: [['field' => '', 'old' => null, 'new' => null]];

            foreach ($fields as $field) {
                $rows[] = [
                    $change['changed_at'], $change['changed_by'], $change['subject'], $change['pos_code'], $change['item_code'],
                    $change['bom_uom'], $change['assembly'], $change['action'], $field['field'], $field['old'], $field['new'],
                    $change['source'], $change['source_file'],
                ];
            }
        }

        return $rows;
    }

    public function title(): string
    {
        return 'BOM Change History';
    }
}
