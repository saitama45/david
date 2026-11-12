<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;

use App\Traits\HasSelections;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use OwenIt\Auditing\Contracts\Auditable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\SupplierFactory> */
    use HasFactory, HasSelections, \OwenIt\Auditing\Auditable, BelongsToEntity;

    protected $fillable = [
        'supplier_code',
        'name',
        'is_active',
        'remarks',
        'is_forapproval_massorders',
        'mass_order_approval_levels',
        'cs_commit_required_from',
    ];

    protected $casts = [
        'is_forapproval_massorders' => 'boolean',
        'mass_order_approval_levels' => 'integer',
        'is_active' => 'boolean',
        'cs_commit_required_from' => 'datetime',
    ];

    /** The ordering template of CPO Orders: purchases from outside vendors, always approved. */
    public const CPO = 'CPO';

    /**
     * Templates ordered the way CPO was in Mass Orders: any delivery date the store's month end
     * count has not closed (no cutoff, no weekday), every store that has the template on any day
     * of its delivery schedule, and the commissary catalogue (SupplierItems::catalogueCodesFor()).
     * They have no CS commit: approving one in Mass Orders Approval commits it and opens its
     * receiving. With its approval switched off, an AO / EO / ALOC order is placed approved and
     * goes straight to Inbound Orders, which receives it at its ordered quantity.
     */
    public const UNRESTRICTED_TEMPLATES = [self::CPO, 'AO', 'EO', 'ALOC'];

    /**
     * Templates whose orders wait in Mass Orders Approval whatever their switch says: CPO,
     * whose typed costs are checked there (CPO Orders places every order pending).
     */
    public const ALWAYS_FOR_APPROVAL = [self::CPO];

    /** Placed already committed (MassOrderService), so it has no CS commit. */
    public const COMMITTED_WHEN_PLACED = 'DROPS';

    /**
     * The DTS Delivery Schedules label of the days Mass Orders' DROPS template (shown there as
     * Fruits and Vegetables) delivers on, beside the DROPS label of Delivery Schedules.
     */
    public const FRUITS_AND_VEGETABLES_SCHEDULE = 'FRUITS AND VEGETABLES';

    /**
     * The delivery schedule labels (d_t_s_delivery_schedules.variant) that put a store on a
     * template's schedule. DROPS is Fruits and Vegetables, whose days are filed under DROPS on
     * Delivery Schedules or under FRUITS AND VEGETABLES on DTS Delivery Schedules: either one
     * counts, as in the Adoption Rate. Every other template is its supplier code alone.
     */
    public static function scheduleVariants(?string $supplierCode): array
    {
        return strtoupper(trim((string) $supplierCode)) === self::COMMITTED_WHEN_PLACED
            ? [self::COMMITTED_WHEN_PLACED, self::FRUITS_AND_VEGETABLES_SCHEDULE]
            : [(string) $supplierCode];
    }

    /**
     * Whether CS commits this template's orders, so Ordering Template Approval can make Inbound
     * Orders wait for that commit (StoreOrder::waitsForCsCommit()). The unrestricted templates
     * are committed by their approval and DROPS when placed: nothing would ever release them.
     */
    public static function hasCsCommit(?string $supplierCode): bool
    {
        $code = strtoupper(trim((string) $supplierCode));

        return $code !== self::COMMITTED_WHEN_PLACED && ! self::isUnrestrictedTemplate($code);
    }

    public static function isUnrestrictedTemplate(?string $supplierCode): bool
    {
        return in_array(strtoupper(trim((string) $supplierCode)), self::UNRESTRICTED_TEMPLATES, true);
    }

    public static function isAlwaysForApproval(?string $supplierCode): bool
    {
        return in_array(strtoupper(trim((string) $supplierCode)), self::ALWAYS_FOR_APPROVAL, true);
    }

    /**
     * Templates whose mass orders wait in Mass Orders Approval: those switched on in
     * Ordering Template Approval, and CPO always.
     */
    public function scopeForMassOrderApproval(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('is_forapproval_massorders', true)->orWhereIn('supplier_code', self::ALWAYS_FOR_APPROVAL));
    }

    public function needsMassOrderApproval(): bool
    {
        return $this->is_forapproval_massorders || self::isAlwaysForApproval($this->supplier_code);
    }

    public function store_orders()
    {
        return $this->hasMany(StoreOrder::class);
    }

    public function dtsDeliverySchedules(): HasMany
    {
        return $this->hasMany(DTSDeliverySchedule::class, 'variant', 'supplier_code');
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'user_suppliers',
            'supplier_code',
            'user_id',
            'supplier_code',
            'id'
        );
    }

    /**
     * Original scopeOptions: Returns suppliers with supplier_code as value.
     * This remains unchanged to avoid breaking existing modules.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Support\Collection
     */
    public function scopeOptions(Builder $query)
    {
        return $query->whereNot('supplier_code', 'DROPS')
                     ->where('is_active', true)
                     ->pluck('name', 'supplier_code');
    }

    /**
     * New scopeReportOptions: Returns suppliers with ID as value and formatted label.
     * This is specifically for reports requiring supplier ID in the filter.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Support\Collection
     */
    public function scopeReportOptions(Builder $query)
    {
        return $query->where('is_active', true)
                     ->get()
                     ->map(function ($supplier) {
                         return [
                             'label' => $supplier->name . ' (' . $supplier->supplier_code . ')',
                             'value' => $supplier->supplier_code,
                         ];
                     });
    }

    public function scopeOptionsDTS(Builder $query)
    {
        return $query->where('supplier_code', 'DROPS')->where('is_active', true)->get()->pluck('name', 'id');
    }
}