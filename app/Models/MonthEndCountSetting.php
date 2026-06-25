<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-entity business rules for the Month End Count process (download window,
 * weekend handling, upload window start, and upload cutoff).
 *
 * This is a settings table keyed by entity_id explicitly — it intentionally does
 * NOT use the BelongsToEntity global scope (settings tables stay global and are
 * read per active entity via the service layer).
 */
class MonthEndCountSetting extends Model
{
    protected $fillable = [
        'entity_id',
        'download_lead_days',
        'download_lead_unit',
        'block_on_weekends',
        'upload_start_days',
        'upload_start_unit',
        'upload_cutoff_enabled',
        'upload_cutoff_days',
        'upload_cutoff_unit',
        'upload_cutoff_time',
    ];

    protected $casts = [
        'download_lead_days' => 'integer',
        'block_on_weekends' => 'boolean',
        'upload_start_days' => 'integer',
        'upload_cutoff_enabled' => 'boolean',
        'upload_cutoff_days' => 'integer',
    ];

    /**
     * Default rules — reproduce the original hard-coded behavior so entities
     * without a saved row keep working exactly as before.
     */
    public static function defaults(): array
    {
        return [
            'download_lead_days' => 3,
            'download_lead_unit' => 'business',
            'block_on_weekends' => true,
            'upload_start_days' => 1,
            'upload_start_unit' => 'calendar',
            'upload_cutoff_enabled' => true,
            'upload_cutoff_days' => 2,
            'upload_cutoff_unit' => 'calendar',
            'upload_cutoff_time' => '23:59:00',
        ];
    }

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }
}
