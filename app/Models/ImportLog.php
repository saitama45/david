<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportLog extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'original_filename',
        'status',
        'processed_count',
        'skipped_count',
        'skipped_file_path',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
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
