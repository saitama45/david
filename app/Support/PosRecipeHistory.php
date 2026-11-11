<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The change history of the BOM List and the POS Masterfile (table pos_recipe_changes).
 *
 * Every write to a BOM line or a POS item goes through here - the forms and the Excel imports
 * alike. The imports write with DB::table(), which the auditing package never sees, so this is
 * recorded explicitly: who, when, the old and new value of each field that changed, and where
 * the change came from (a form, an import and its file, or an allowed repeat line).
 */
final class PosRecipeHistory
{
    public const BOM_LINE = 'bom_line';

    public const POS_ITEM = 'pos_item';

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    public const FORM = 'form';

    public const IMPORT = 'import';

    public const ALLOWED_REPEAT = 'allowed_repeat';

    /** Copied from the audits table: form changes made before this history existed. */
    public const AUDIT_LOG = 'audit_log';

    public const BOM_FIELDS = ['POSCode', 'POSDescription', 'Assembly', 'ItemCode', 'ItemDescription', 'RecPercent', 'RecipeQty', 'RecipeUOM', 'BOMQty', 'BOMUOM', 'UnitCost', 'TotalCost'];

    public const POS_FIELDS = ['POSCode', 'POSDescription', 'POSName', 'Category', 'SubCategory', 'UOM', 'SRP', 'DeliveryPrice', 'TableVibePrice', 'is_active'];

    private const NUMERIC = ['RecPercent', 'RecipeQty', 'BOMQty', 'UnitCost', 'TotalCost', 'SRP', 'DeliveryPrice', 'TableVibePrice'];

    /**
     * @param  Model|array<string, mixed>|object|null  $old  the line before (null when created)
     * @param  Model|array<string, mixed>|object|null  $new  the line after (null when deleted)
     */
    public static function bomLine(string $action, mixed $old, mixed $new, string $source = self::FORM, ?string $file = null, ?string $changedAt = null, ?int $changedBy = null, ?int $auditId = null): bool
    {
        return self::record(self::BOM_LINE, self::BOM_FIELDS, $action, $old, $new, $source, $file, $changedAt, $changedBy, $auditId);
    }

    /**
     * @param  Model|array<string, mixed>|object|null  $old  the item before (null when created)
     * @param  Model|array<string, mixed>|object|null  $new  the item after (null when deleted)
     */
    public static function posItem(string $action, mixed $old, mixed $new, string $source = self::FORM, ?string $file = null, ?string $changedAt = null, ?int $changedBy = null, ?int $auditId = null): bool
    {
        return self::record(self::POS_ITEM, self::POS_FIELDS, $action, $old, $new, $source, $file, $changedAt, $changedBy, $auditId);
    }

    /** A field's value as the history compares and shows it: numbers without trailing zeros, blanks as null. */
    public static function normalize(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (in_array($field, self::NUMERIC, true) && is_numeric($value)) {
            $text = rtrim(rtrim(number_format((float) $value, 7, '.', ''), '0'), '.');

            return $text === '-0' ? '0' : $text;
        }

        if ($field === 'is_active') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** Writes the row; false when an update changed none of the tracked fields (nothing to record). */
    private static function record(string $subject, array $fields, string $action, mixed $old, mixed $new, string $source, ?string $file, ?string $changedAt, ?int $changedBy, ?int $auditId): bool
    {
        $oldRaw = self::raw($old);
        $newRaw = self::raw($new);
        $before = $oldRaw === null ? [] : self::pick($oldRaw, $fields);
        $after = $newRaw === null ? [] : self::pick($newRaw, $fields);

        if ($action === self::UPDATED) {
            $changed = array_values(array_filter($fields, fn ($field) => ($before[$field] ?? null) !== ($after[$field] ?? null)));

            if ($changed === []) {
                return false;
            }

            $before = array_intersect_key($before, array_flip($changed)) + array_fill_keys($changed, null);
            $after = array_intersect_key($after, array_flip($changed)) + array_fill_keys($changed, null);
        } else {
            $before = array_filter($before, fn ($value) => $value !== null);
            $after = array_filter($after, fn ($value) => $value !== null);
        }

        $identity = fn (string $field) => self::normalize($field, $newRaw[$field] ?? null) ?? self::normalize($field, $oldRaw[$field] ?? null);
        $now = now();

        DB::table('pos_recipe_changes')->insert([
            'entity_id' => $newRaw['entity_id'] ?? $oldRaw['entity_id'] ?? app(EntityContext::class)->id(),
            'subject' => $subject,
            'subject_id' => $newRaw['id'] ?? $oldRaw['id'] ?? null,
            'pos_code' => $identity('POSCode'),
            'item_code' => $subject === self::BOM_LINE ? $identity('ItemCode') : null,
            'bom_uom' => $subject === self::BOM_LINE ? $identity('BOMUOM') : null,
            'assembly' => $subject === self::BOM_LINE ? $identity('Assembly') : null,
            'action' => $action,
            'old_values' => $before === [] ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'new_values' => $after === [] ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'source' => $source,
            'source_file' => $file !== null ? mb_substr($file, 0, 255) : null,
            'changed_by' => $changedBy ?? Auth::id(),
            'changed_at' => $changedAt ?? $now,
            'audit_id' => $auditId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }

    /** @return array<string, mixed>|null */
    private static function raw(mixed $row): ?array
    {
        return match (true) {
            $row === null => null,
            $row instanceof Model => $row->getAttributes(),
            default => (array) $row,
        };
    }

    /** @return array<string, ?string> */
    private static function pick(array $row, array $fields): array
    {
        $picked = [];
        foreach ($fields as $field) {
            $picked[$field] = self::normalize($field, $row[$field] ?? null);
        }

        return $picked;
    }
}
