<?php

namespace App\Http\Services;

use App\Support\EntityContext;
use App\Support\PosRecipeHistory;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The BOM Change History page and its Excel export: who changed which BOM line or POS item,
 * when, each field's old and new value, and where the change came from.
 */
class PosRecipeHistoryService
{
    public const PER_PAGE = 25;

    /** The most users the Changed By picker lists at a time; typing more narrows them. */
    public const USER_SEARCH_LIMIT = 8;

    /** Below this many characters the Changed By picker only finds an exact name. */
    public const MIN_SEARCH_CHARS = 3;

    public const FIELD_LABELS = [
        'POSCode' => 'POS Code', 'POSDescription' => 'POS Description', 'POSName' => 'POS Name',
        'Assembly' => 'Assembly', 'ItemCode' => 'Item Code', 'ItemDescription' => 'Item Description',
        'RecPercent' => 'Rec Percent', 'RecipeQty' => 'Recipe Qty', 'RecipeUOM' => 'Recipe UOM',
        'BOMQty' => 'BOM Qty', 'BOMUOM' => 'BOM UOM', 'UnitCost' => 'Unit Cost', 'TotalCost' => 'Total Cost',
        'Category' => 'Category', 'SubCategory' => 'Sub Category', 'UOM' => 'UOM', 'SRP' => 'SRP',
        'DeliveryPrice' => 'Delivery Price', 'TableVibePrice' => 'Table Vibe Price', 'is_active' => 'Active',
    ];

    public const SUBJECTS = [PosRecipeHistory::BOM_LINE => 'BOM line', PosRecipeHistory::POS_ITEM => 'POS item'];

    public const ACTIONS = [PosRecipeHistory::CREATED => 'Added', PosRecipeHistory::UPDATED => 'Changed', PosRecipeHistory::DELETED => 'Deleted'];

    public const SOURCES = [
        PosRecipeHistory::FORM => 'Form',
        PosRecipeHistory::IMPORT => 'Excel import',
        PosRecipeHistory::ALLOWED_REPEAT => 'Excel import (repeat line allowed)',
        PosRecipeHistory::AUDIT_LOG => 'Form (from the audit log)',
    ];

    /**
     * The changes the filters select, newest first, within the active entity (a change with no
     * entity - a line from before entities - shows in every entity, as the line itself does).
     *
     * @param  array<string, mixed>  $filters  pos_code, item_code, date_from, date_to, changed_by, source, action, subject
     */
    public function query(array $filters): Builder
    {
        $entityId = app(EntityContext::class)->id();
        $like = fn (string $text) => '%' . str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], trim($text)) . '%';

        return DB::table('pos_recipe_changes as prc')
            ->leftJoin('users as u', 'u.id', '=', 'prc.changed_by')
            ->when($entityId !== null, fn ($query) => $query->where(fn ($entity) => $entity->where('prc.entity_id', $entityId)->orWhereNull('prc.entity_id')))
            ->when(filled($filters['pos_code'] ?? null), fn ($query) => $query->where('prc.pos_code', 'like', $like($filters['pos_code'])))
            ->when(filled($filters['item_code'] ?? null), fn ($query) => $query->where('prc.item_code', 'like', $like($filters['item_code'])))
            ->when(filled($filters['date_from'] ?? null), fn ($query) => $query->where('prc.changed_at', '>=', $filters['date_from'] . ' 00:00:00'))
            ->when(filled($filters['date_to'] ?? null), fn ($query) => $query->where('prc.changed_at', '<=', $filters['date_to'] . ' 23:59:59'))
            ->when(filled($filters['changed_by'] ?? null), fn ($query) => $query->where('prc.changed_by', (int) $filters['changed_by']))
            ->when(isset(self::SOURCES[$filters['source'] ?? '']), fn ($query) => $query->where('prc.source', $filters['source']))
            ->when(isset(self::ACTIONS[$filters['action'] ?? '']), fn ($query) => $query->where('prc.action', $filters['action']))
            ->when(isset(self::SUBJECTS[$filters['subject'] ?? '']), fn ($query) => $query->where('prc.subject', $filters['subject']))
            ->orderByDesc('prc.changed_at')
            ->orderByDesc('prc.id')
            ->select(['prc.*', DB::raw("LTRIM(RTRIM(CONCAT(u.first_name, ' ', u.last_name))) as changed_by_name")]);
    }

    /**
     * One change as the page shows it.
     *
     * @return array<string, mixed>
     */
    public function present(object $row): array
    {
        $old = (array) (json_decode((string) $row->old_values, true) ?: []);
        $new = (array) (json_decode((string) $row->new_values, true) ?: []);
        $fields = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));
        usort($fields, fn ($a, $b) => array_search($a, array_keys(self::FIELD_LABELS)) <=> array_search($b, array_keys(self::FIELD_LABELS)));

        return [
            'id' => (int) $row->id,
            'changed_at' => Carbon::parse($row->changed_at)->format('M j, Y g:i A'),
            'changed_by' => filled($row->changed_by_name) ? $row->changed_by_name : ($row->changed_by ? "User #{$row->changed_by}" : 'System'),
            'subject' => self::SUBJECTS[$row->subject] ?? $row->subject,
            'pos_code' => $row->pos_code,
            'item_code' => $row->item_code,
            'bom_uom' => $row->bom_uom,
            'assembly' => $row->assembly,
            'action' => self::ACTIONS[$row->action] ?? $row->action,
            'action_key' => $row->action,
            'source' => self::SOURCES[$row->source] ?? $row->source,
            'source_file' => $row->source_file,
            'changes' => array_map(fn ($field) => [
                'field' => self::FIELD_LABELS[$field] ?? $field,
                'old' => $old[$field] ?? null,
                'new' => $new[$field] ?? null,
            ], $fields),
        ];
    }

    /**
     * The users who made a change in this entity, for the Changed By picker: nothing until
     * something is typed, and below MIN_SEARCH_CHARS only an exact name.
     *
     * @return array<int, array{id: int, name: string, email: ?string}>
     */
    public function users(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $entityId = app(EntityContext::class)->id();
        $literal = str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $term);
        $fullName = "LTRIM(RTRIM(CONCAT(first_name, ' ', last_name)))";

        return DB::table('users')
            ->whereIn('id', DB::table('pos_recipe_changes')->whereNotNull('changed_by')
                ->when($entityId !== null, fn ($query) => $query->where(fn ($entity) => $entity->where('entity_id', $entityId)->orWhereNull('entity_id')))
                ->select('changed_by'))
            ->when(mb_strlen($term) < self::MIN_SEARCH_CHARS,
                // A name shorter than that is found when typed in full.
                fn ($query) => $query->where(fn ($q) => $q->where('first_name', $term)->orWhere('last_name', $term)->orWhereRaw("{$fullName} = ?", [$term])),
                fn ($query) => $query->where(fn ($q) => $q->whereRaw("{$fullName} LIKE ?", ["%{$literal}%"])->orWhere('email', 'like', "%{$literal}%"))
                    ->orderByRaw("CASE WHEN {$fullName} LIKE ? THEN 0 ELSE 1 END", ["{$literal}%"]))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(self::USER_SEARCH_LIMIT)
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn ($user) => ['id' => (int) $user->id, 'name' => trim($user->first_name . ' ' . $user->last_name), 'email' => $user->email])
            ->all();
    }
}
