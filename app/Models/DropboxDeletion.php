<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DropboxDeletion extends Model
{
    protected $fillable = [
        'user_id',
        'video_id',
        'dropbox_path',
        'filename',
        'file_type',
        'metadata',
        'processed',
        'deleted_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'processed' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user that owns the deletion record
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the video that was deleted (if linked)
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
