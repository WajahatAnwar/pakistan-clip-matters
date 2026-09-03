<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoTag extends Model
{
    protected $fillable = [
        'video_id',
        'tag',
        'normalized_tag',
    ];

    /**
     * Get the video that owns the tag
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
