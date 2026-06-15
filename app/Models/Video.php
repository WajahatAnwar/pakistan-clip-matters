<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Video extends Model
{
    protected $fillable = [
        'user_id',
        'dropbox_path',
        'filename',
        'extension',
        'size_mb',
        'title',
        'description',
        'processing_status',
        'processing_error',
        'youtube_video_id',
        'youtube_url',
        'youtube_privacy',
        'transcript_id',
        'transcript_text',
        'language_detected',
        'audio_duration_seconds',
        'confidence',
        'speakers_count',
        'speakers_data',
        'transcript_urdu',
        'transcript_english',
        'summary',
        'summary_urdu',
        'summary_english',
        'diarization_data',
        'identification_data',
        'speaker_mapping',
        'pyannote_job_id',
        'pyannote_status',
        'pyannote_error',
        'processing_started_at',
        'processing_completed_at',
        'video_created_at',
        'approval_status',
        'approved_by',
        'approved_at',
        'is_archived',
        'archived_by',
        'archived_at',
        'rejection_reason',
    ];

    protected $casts = [
        'speakers_data' => 'array',
        'transcript_urdu' => 'array',
        'transcript_english' => 'array',
        'diarization_data' => 'array',
        'identification_data' => 'array',
        'speaker_mapping' => 'array',
        'size_mb' => 'decimal:2',
        'confidence' => 'decimal:4',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
        'video_created_at' => 'datetime',
        'approved_at' => 'datetime',
        'archived_at' => 'datetime',
        'is_archived' => 'boolean',
    ];

    /**
     * Get the user that owns the video
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who approved the video
     */
    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who archived the video
     */
    public function archivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    /**
     * Get the video's embedding record
     */
    public function embedding()
    {
        return $this->hasOne(VideoEmbedding::class);
    }

    /**
     * Get audio duration in minutes
     */
    public function getAudioDurationMinutesAttribute(): float
    {
        return $this->audio_duration_seconds ? round($this->audio_duration_seconds / 60, 2) : 0;
    }

    /**
     * Check if video processing is complete
     */
    public function isCompleted(): bool
    {
        return $this->processing_status === 'completed';
    }

    /**
     * Check if video processing failed
     */
    public function isFailed(): bool
    {
        return $this->processing_status === 'failed';
    }

    /**
     * Check if video is currently processing
     */
    public function isProcessing(): bool
    {
        return $this->processing_status === 'processing';
    }

    /**
     * Mark video as processing
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'processing_status' => 'processing',
            'processing_started_at' => now(),
        ]);
    }

    /**
     * Mark video as completed
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'processing_status' => 'completed',
            'processing_completed_at' => now(),
        ]);
    }

    /**
     * Mark video as failed
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'processing_status' => 'failed',
            'processing_error' => $error,
            'processing_completed_at' => now(),
        ]);
    }

    /**
     * Approve the video
     */
    public function approve(int $userId): void
    {
        $this->update([
            'approval_status' => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
            // Clear archived status when approving
            'is_archived' => false,
            'archived_by' => null,
            'archived_at' => null,
        ]);
    }

    /**
     * Reject the video
     */
    public function reject(int $userId, ?string $reason = null): void
    {
        $this->update([
            'approval_status' => 'rejected',
            'approved_by' => $userId,
            'approved_at' => now(),
            'rejection_reason' => $reason,
            // Clear archived status when rejecting
            'is_archived' => false,
            'archived_by' => null,
            'archived_at' => null,
        ]);
    }

    /**
     * Archive the video
     */
    public function archive(int $userId): void
    {
        $this->update([
            'is_archived' => true,
            'archived_by' => $userId,
            'archived_at' => now(),
        ]);
    }

    /**
     * Unarchive the video
     */
    public function unarchive(): void
    {
        $this->update([
            'is_archived' => false,
            'archived_by' => null,
            'archived_at' => null,
        ]);
    }

    /**
     * Reset approval status to pending
     */
    public function resetApproval(): void
    {
        $this->update([
            'approval_status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    /**
     * Check if video is approved
     */
    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    /**
     * Check if video is rejected
     */
    public function isRejected(): bool
    {
        return $this->approval_status === 'rejected';
    }

    /**
     * Check if video is pending approval
     */
    public function isPendingApproval(): bool
    {
        return $this->approval_status === 'pending';
    }

    /**
     * Check if video is archived
     */
    public function isArchived(): bool
    {
        return $this->is_archived === true;
    }

    /**
     * Scope for non-archived videos
     */
    public function scopeNotArchived($query)
    {
        return $query->where('is_archived', false);
    }

    /**
     * Scope for archived videos
     */
    public function scopeArchived($query)
    {
        return $query->where('is_archived', true);
    }

    /**
     * Scope by approval status
     */
    public function scopeApprovalStatus($query, string $status)
    {
        return $query->where('approval_status', $status);
    }

    /**
     * Scope for searchable videos — only completed, approved, non-archived.
     * Use this scope everywhere search eligibility is needed.
     */
    public function scopeSearchable($query)
    {
        return $query->where('processing_status', 'completed')
                     ->where('approval_status', 'approved')
                     ->where('is_archived', false);
    }
}
