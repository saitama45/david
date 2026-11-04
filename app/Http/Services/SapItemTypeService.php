<?php

namespace App\Http\Services;

use App\Models\SapItemType;
use App\Models\SapItemTypeAssignment;
use Illuminate\Support\Collection;
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

    /**
     * Every item code's Item Type in the active entity, for a report that shows it and filters
     * on it. Keyed by normalize(): SQL Server matches a code whatever its case, a PHP key does not.
     *
     * @return Collection<string, SapItemTypeAssignment> each with name, inventory_class and counts_in_totals
     */
    public function byItemCode(): Collection
    {
        return SapItemTypeAssignment::query()
            ->join('sap_item_types as sit', 'sit.id', '=', 'sap_item_type_assignments.sap_item_type_id')
            ->get(['sap_item_type_assignments.item_code', 'sit.name', 'sit.inventory_class', 'sit.counts_in_totals'])
            ->keyBy(fn ($type) => self::normalize($type->item_code));
    }

    /**
     * Whether an item belongs on a report under its inventory_class and filter_item_type filters.
     *
     * An item with no Item Type stays on the INVENTORIABLE list: only a type marked otherwise
     * takes an item off it, because most item codes have no type and would vanish from the report.
     * The filters are the reports' own, never InventoryMovementService's, so the Month End Count
     * template still reads every item.
     */
    public static function inReport(Collection $types, ?string $itemCode, array $filters): bool
    {
        $type = $types->get(self::normalize($itemCode));

        if (($type?->inventory_class ?? SapItemType::INVENTORIABLE) !== $filters['inventory_class']) {
            return false;
        }

        return blank($filters['filter_item_type'] ?? null)
            || mb_stripos((string) $type?->name, trim($filters['filter_item_type'])) !== false;
    }

    /**
     * Whether an item's figures count in a report's totals. Only a type marked otherwise leaves
     * an item out, and it stays on the list either way; an item with no Item Type counts.
     */
    public static function inTotals(Collection $types, ?string $itemCode): bool
    {
        $type = $types->get(self::normalize($itemCode));

        return $type === null || (bool) $type->counts_in_totals;
    }

    public static function normalize(?string $name): string
    {
        return strtoupper(trim((string) $name));
    }
}
