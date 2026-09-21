<?php

namespace App\Http\Services;

use App\Models\SapItemType;
use App\Models\SapItemTypeAssignment;
use Illuminate\Support\Facades\DB;

/** Item Types for SAP items. The type belongs to the ItemCode, shared by all its UOM rows. */
class SapItemTypeService
{
    /**
     * An entity's types keyed by their comparable name (trimmed, upper-cased),
     * so a file saying " food " matches FOOD.
     *
     * @return array<string, array{id: int, name: string, is_active: bool}>
     */
    public function nameMap(int $entityId): array
    {
        return DB::table('sap_item_types')->where('entity_id', $entityId)
            ->get(['id', 'name', 'is_active'])
            ->mapWithKeys(fn ($type) => [self::normalize($type->name) => [
                'id' => (int) $type->id, 'name' => $type->name, 'is_active' => (bool) $type->is_active,
            ]])
            ->all();
    }

    /** Set, or clear with null, the type of an ItemCode. Audited: this goes through Eloquent. */
    public function assign(int $entityId, string $itemCode, ?int $typeId): void
    {
        SapItemTypeAssignment::withoutEntityScope()->updateOrCreate(
            ['entity_id' => $entityId, 'item_code' => $itemCode],
            ['sap_item_type_id' => $typeId],
        );
    }

    public function typeIdFor(int $entityId, string $itemCode): ?int
    {
        $id = DB::table('sap_item_type_assignments')
            ->where('entity_id', $entityId)->where('item_code', $itemCode)
            ->value('sap_item_type_id');

        return $id === null ? null : (int) $id;
    }

    /** Types to offer in a dropdown: the active ones, plus the item's current type if it was deactivated. */
    public function options(?int $currentTypeId = null)
    {
        return SapItemType::query()
            ->where(fn ($q) => $q->where('is_active', true)
                ->when($currentTypeId, fn ($q) => $q->orWhere('id', $currentTypeId)))
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);
    }

    public static function normalize(?string $name): string
    {
        return strtoupper(trim((string) $name));
    }
}
