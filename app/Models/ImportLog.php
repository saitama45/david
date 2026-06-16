<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToEntity;

class ImportLog extends Model
{
    use BelongsToEntity;

    protected $fillable = [
        'entity_id',
        'user_id',
        'type',
        'original_filename',
        'source_file_path',
        'status',
        'processed_count',
        'skipped_count',
        'skipped_file_path',
        'error_message',
        'processing_started_at',
        'last_heartbeat_at',
        'failed_at',
        'completed_at',
    ];

    protected $casts = [
        'processing_started_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'failed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function storeBranches()
    {
        return $this->belongsToMany(
            StoreBranch::class,
            'import_log_store_branches',
            'import_log_id',
            'store_branch_id'
        )->withTimestamps();
    }
}
