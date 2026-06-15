<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VideoEmbedding extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_id',
        'qdrant_collection',
        'segments_count',
        'vector_dimensions',
        'status',
        'error_message',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    /**
     * Default attribute values
     */
    protected $attributes = [
        'vector_dimensions' => 3072, // OpenAI text-embedding-3-large
        'qdrant_collection' => 'video_transcript_segments',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * Get the video that owns the embedding
     */
    public function video()
    {
        return $this->belongsTo(Video::class);
    }

    /**
     * Scope to get only completed embeddings
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope to get failed embeddings
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope to get pending embeddings
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Mark embedding as processing
     */
    public function markAsProcessing()
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    /**
     * Mark embedding as completed
     */
    public function markAsCompleted($segmentsCount, $collection = 'video_transcript_segments')
    {
        $this->update([
            'status' => 'completed',
            'segments_count' => $segmentsCount,
            'qdrant_collection' => $collection,
            'completed_at' => now(),
            'error_message' => null,
            'failed_at' => null,
        ]);
    }

    /**
     * Mark embedding as failed
     */
    public function markAsFailed($errorMessage)
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'failed_at' => now(),
        ]);
    }

    /**
     * Check if embedding is completed
     */
    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    /**
     * Check if embedding is processing
     */
    public function isProcessing()
    {
        return $this->status === 'processing';
    }

    /**
     * Check if embedding has failed
     */
    public function hasFailed()
    {
        return $this->status === 'failed';
    }
}
