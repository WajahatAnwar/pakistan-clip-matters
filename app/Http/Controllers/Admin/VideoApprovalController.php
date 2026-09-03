<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Jobs\ProcessVideoJob;
use App\Services\DropboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class VideoApprovalController extends Controller
{
    /**
     * Display the video approval management page
     */
    public function index()
    {
        $user = Auth::user();
        
        // All admin roles (superAdmin, admin, manager) can view archived tab
        // Only superAdmin and admin can manage (interact with) videos
        return Inertia::render('AdminSide/VideoApproval/index', [
            'canViewArchived' => $user->hasAnyRole(['superAdmin', 'admin', 'manager']),
            'canManageVideos' => $user->hasAnyRole(['superAdmin', 'admin']),
        ]);
    }

    /**
     * Get paginated list of videos for approval management
     * All admin roles can see all tabs including Archived
     */
    public function list(Request $request)
    {
        $user = Auth::user();
        $perPage = $request->get('per_page', 10);
        $tab = $request->get('tab', 'pending'); // pending, approved, rejected, archived
        $search = $request->get('search');
        $sort = $request->get('sort', 'newest'); // Sort order: newest or oldest

        $query = Video::with(['user:id,name', 'approvedByUser:id,name', 'archivedByUser:id,name', 'embedding', 'tags'])
            ->select([
                'id',
                'user_id',
                'dropbox_path',
                'filename',
                'title',
                'description',
                'youtube_video_id',
                'youtube_url',
                'processing_status',
                'approval_status',
                'approved_by',
                'approved_at',
                'is_archived',
                'archived_by',
                'archived_at',
                'transcript_id',
                'speakers_data',
                'pyannote_job_id',
                'diarization_data',
                'identification_data',
                'video_created_at',
                'created_at',
                'has_audio',
                'processing_error',
            ]);

        // Apply search filter
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('filename', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        // Apply tab-specific filters
        switch ($tab) {
            case 'archived':
                // All admin roles can access archived
                $query->where('is_archived', true);
                break;
            case 'approved':
                $query->where('is_archived', false)->where('approval_status', 'approved');
                break;
            case 'rejected':
                $query->where('is_archived', false)->where('approval_status', 'rejected');
                break;
            case 'failed':
                $query->where('is_archived', false)->where('processing_status', 'failed');
                break;
            case 'pending':
            default:
                $query->where('is_archived', false)
                      ->where('approval_status', 'pending')
                      ->where('processing_status', '!=', 'failed');
                break;
        }

        // Apply sorting based on sort parameter
        // Videos under processing always appear on top,
        // then sort by created_at (sync/import time) and video_created_at
        $sortOrder = $sort === 'oldest' ? 'asc' : 'desc';
        $videos = $query
            ->orderByRaw("CASE WHEN processing_status = 'processing' THEN 0 ELSE 1 END")
            ->orderBy('created_at', $sortOrder)
            ->orderBy('video_created_at', $sortOrder)
            ->paginate($perPage);

        // Transform videos to add computed fields
        $videos->getCollection()->transform(function ($video) {
            $video->onDropbox = (bool) $video->dropbox_path;
            $video->onYoutube = (bool) $video->youtube_video_id;
            $video->isTranscript = $video->transcript_id && $video->speakers_data !== null;
            $video->isTagged = $video->pyannote_job_id && $video->diarization_data !== null && $video->identification_data !== null;
            $video->isTracked = $video->isTranscript && $video->isTagged && $video->onYoutube && $video->onDropbox;
            $video->hasEmbedding = $video->embedding && $video->embedding->isCompleted();
            $video->tags_list = $video->tags ? $video->tags->pluck('tag')->toArray() : [];

            return $video->makeHidden([
                'dropbox_path',
                'speakers_data',
                'diarization_data',
                'identification_data',
                'embedding',
            ]);
        });

        return response()->json($videos);
    }

    /**
     * Get a temporary preview link for a video from Dropbox
     */
    public function getPreviewLink(Video $video)
    {
        $user = Auth::user();

        Log::info('Preview link requested', [
            'video_id' => $video->id,
            'title' => $video->title,
            'dropbox_path' => $video->dropbox_path,
            'user_id' => $user->id
        ]);

        // Check if video has a dropbox path
        if (!$video->dropbox_path) {
            Log::warning('Video has no Dropbox path', ['video_id' => $video->id]);
            return response()->json(['error' => 'Video does not have a Dropbox path'], 404);
        }

        try {
            $dropboxService = new DropboxService($user);
            
            Log::info('Calling Dropbox getTemporaryLink', [
                'video_id' => $video->id,
                'path' => $video->dropbox_path
            ]);
            
            $temporaryLink = $dropboxService->getTemporaryLink($video->dropbox_path);

            if (!$temporaryLink) {
                Log::error('Dropbox returned null/empty link', [
                    'video_id' => $video->id,
                    'path' => $video->dropbox_path
                ]);
                return response()->json(['error' => 'Failed to generate preview link - Dropbox returned no link'], 500);
            }

            Log::info('Preview link generated successfully', [
                'video_id' => $video->id,
                'link_length' => strlen($temporaryLink)
            ]);

            // Also get the shared link for viewing in Dropbox's web player
            $dropboxPreviewUrl = null;
            try {
                $dropboxPreviewUrl = $dropboxService->getSharedLink($video->dropbox_path);
                Log::info('Dropbox shared link generated', [
                    'video_id' => $video->id,
                    'has_shared_link' => !empty($dropboxPreviewUrl)
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to get Dropbox shared link, continuing without it', [
                    'video_id' => $video->id,
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'preview_url' => $temporaryLink,
                'dropbox_preview_url' => $dropboxPreviewUrl,
                'video_id' => $video->id,
                'title' => $video->title,
                'dropbox_path' => $video->dropbox_path,
                'expires_in' => '4 hours' // Dropbox temp links expire after 4 hours
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get video preview link', [
                'video_id' => $video->id,
                'dropbox_path' => $video->dropbox_path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => 'Failed to generate preview link: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Approve a video and dispatch processing job
     * Only superAdmin and admin can approve videos
     */
    public function approve(Request $request, Video $video)
    {
        $user = Auth::user();

        // Check if user has permission to manage video approvals
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized. Only admins can manage video approvals.'], 403);
        }

        $video->approve($user->id);

        // Dispatch processing job if video hasn't been fully processed
        // Skip dispatching if the video is marked as a failed video with no audio
        $isFailedWithNoAudio = $video->processing_status === 'failed' && $video->has_audio === false;
        if ($video->processing_status !== 'completed' && !$isFailedWithNoAudio) {
            try {
                ProcessVideoJob::dispatch(
                    $video->user_id,
                    $video->id,
                    $video->dropbox_path,
                    $video->title,
                    $video->description
                );

                Log::info('Video processing dispatched after approval', [
                    'video_id' => $video->id,
                    'approved_by' => $user->id
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to dispatch video processing after approval', [
                    'video_id' => $video->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return response()->json([
            'message' => 'Video approved and processing started',
            'video' => $video->fresh(),
        ]);
    }

    /**
     * Reject a video
     * Only superAdmin and admin can reject videos
     */
    public function reject(Request $request, Video $video)
    {
        $user = Auth::user();

        // Check if user has permission to manage video approvals
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized. Only admins can manage video approvals.'], 403);
        }

        $reason = $request->input('rejection_reason');
        $video->reject($user->id, $reason);

        return response()->json([
            'message' => 'Video rejected successfully',
            'video' => $video->fresh(),
        ]);
    }

    /**
     * Archive a video
     * Only superAdmin and admin can archive videos
     */
    public function archive(Request $request, Video $video)
    {
        $user = Auth::user();

        // Check if user has permission to manage video approvals
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized. Only admins can manage video approvals.'], 403);
        }

        $video->archive($user->id);

        return response()->json([
            'message' => 'Video archived successfully',
            'video' => $video->fresh(),
        ]);
    }

    /**
     * Unarchive a video
     * Only superAdmin and admin can unarchive videos
     */
    public function unarchive(Request $request, Video $video)
    {
        $user = Auth::user();

        // Check if user has permission to manage video approvals
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized. Only admins can manage video approvals.'], 403);
        }

        $video->unarchive();

        return response()->json([
            'message' => 'Video unarchived successfully',
            'video' => $video->fresh(),
        ]);
    }

    /**
     * Reset video approval status to pending
     * Only superAdmin and admin can reset video status
     */
    public function resetStatus(Request $request, Video $video)
    {
        $user = Auth::user();

        // Check if user has permission to manage video approvals
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized. Only admins can manage video approvals.'], 403);
        }

        $video->resetApproval();

        return response()->json([
            'message' => 'Video status reset to pending',
            'video' => $video->fresh(),
        ]);
    }

    /**
     * Bulk approve videos and dispatch processing jobs
     * Only superAdmin and admin can bulk approve videos
     */
    public function bulkApprove(Request $request)
    {
        $user = Auth::user();

        // Check if user has permission to manage video approvals
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized. Only admins can manage video approvals.'], 403);
        }

        $request->validate([
            'video_ids' => 'required|array',
            'video_ids.*' => 'exists:videos,id',
        ]);
        $videoIds = $request->video_ids;

        // Filter out archived videos for non-superAdmin
        $query = Video::whereIn('id', $videoIds);
        if (!$user->hasRole('superAdmin')) {
            $query->where('is_archived', false);
        }

        // Get videos that need to be approved
        $videos = $query->where('approval_status', '!=', 'approved')->get();

        // Approve each video and dispatch processing job
        $approvedCount = 0;
        $processedCount = 0;

        foreach ($videos as $video) {
            $video->update([
                'approval_status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);
            $approvedCount++;

            // Dispatch processing job if video hasn't been fully processed
            // Skip dispatching if the video is marked as a failed video with no audio
            $isFailedWithNoAudio = $video->processing_status === 'failed' && $video->has_audio === false;
            if ($video->processing_status !== 'completed' && !$isFailedWithNoAudio) {
                try {
                    ProcessVideoJob::dispatch(
                        $video->user_id,
                        $video->id,
                        $video->dropbox_path,
                        $video->title,
                        $video->description
                    );
                    $processedCount++;

                    Log::info('Video processing dispatched after bulk approval', [
                        'video_id' => $video->id,
                        'approved_by' => $user->id
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to dispatch video processing after bulk approval', [
                        'video_id' => $video->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return response()->json([
            'message' => "{$approvedCount} video(s) approved, {$processedCount} queued for processing",
            'count' => $approvedCount,
            'processing_count' => $processedCount,
        ]);
    }

    /**
     * Bulk reject videos
     * Only superAdmin and admin can bulk reject videos
     */
    public function bulkReject(Request $request)
    {
        $user = Auth::user();

        // Check if user has permission to manage video approvals
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized. Only admins can manage video approvals.'], 403);
        }

        $request->validate([
            'video_ids' => 'required|array',
            'video_ids.*' => 'exists:videos,id',
        ]);
        $videoIds = $request->video_ids;

        // Filter out archived videos for non-superAdmin
        $query = Video::whereIn('id', $videoIds);
        if (!$user->hasRole('superAdmin')) {
            $query->where('is_archived', false);
        }

        $count = $query->update([
            'approval_status' => 'rejected',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'rejection_reason' => $request->input('rejection_reason'),
        ]);

        return response()->json([
            'message' => "{$count} video(s) rejected successfully",
            'count' => $count,
        ]);
    }

    /**
     * Bulk archive videos
     * Only superAdmin and admin can bulk archive videos
     */
    public function bulkArchive(Request $request)
    {
        $user = Auth::user();

        // Check if user has permission to manage video approvals
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized. Only admins can manage video approvals.'], 403);
        }

        $request->validate([
            'video_ids' => 'required|array',
            'video_ids.*' => 'exists:videos,id',
        ]);
        $videoIds = $request->video_ids;

        $count = Video::whereIn('id', $videoIds)->update([
            'is_archived' => true,
            'archived_by' => $user->id,
            'archived_at' => now(),
        ]);

        return response()->json([
            'message' => "{$count} video(s) archived successfully",
            'count' => $count,
        ]);
    }

    /**
     * Bulk unarchive videos
     * Only superAdmin and admin can bulk unarchive videos
     */
    public function bulkUnarchive(Request $request)
    {
        $user = Auth::user();

        // Check if user has permission to manage video approvals
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized. Only admins can manage video approvals.'], 403);
        }

        $request->validate([
            'video_ids' => 'required|array',
            'video_ids.*' => 'exists:videos,id',
        ]);

        $videoIds = $request->video_ids;

        $count = Video::whereIn('id', $videoIds)->update([
            'is_archived' => false,
            'archived_by' => null,
            'archived_at' => null,
        ]);

        return response()->json([
            'message' => "{$count} video(s) unarchived successfully",
            'count' => $count,
        ]);
    }

    /**
     * Bulk reset video status to pending
     * Only superAdmin and admin can bulk reset video status
     */
    public function bulkResetStatus(Request $request)
    {
        $user = Auth::user();

        // Check if user has permission to manage video approvals
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized. Only admins can manage video approvals.'], 403);
        }

        $request->validate([
            'video_ids' => 'required|array',
            'video_ids.*' => 'exists:videos,id',
        ]);

        $videoIds = $request->video_ids;
        $videos = Video::whereIn('id', $videoIds)->get();
        
        $count = 0;
        foreach ($videos as $video) {
            $video->resetApproval();
            $count++;
        }

        return response()->json([
            'message' => "{$count} video(s) reset to pending successfully",
            'count' => $count,
        ]);
    }

    /**
     * Update audio status for a video
     */
    public function updateAudioStatus(Request $request, Video $video)
    {
        $user = Auth::user();
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'has_audio' => 'nullable|boolean',
        ]);

        $video->update([
            'has_audio' => $request->has_audio,
        ]);

        return response()->json([
            'message' => 'Audio status updated successfully',
            'video' => $video->fresh(['tags']),
        ]);
    }

    /**
     * Get tags for a video
     */
    public function getTags(Request $request, Video $video)
    {
        $user = Auth::user();
        if (!$user->hasAnyRole(['superAdmin', 'admin', 'manager'])) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'tags' => $video->tags()->pluck('tag')->toArray(),
        ]);
    }

    /**
     * Save tags for a video
     */
    public function saveTags(Request $request, Video $video)
    {
        $user = Auth::user();
        if (!$user->hasAnyRole(['superAdmin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'tags' => 'array',
            'tags.*' => 'string|max:50',
        ]);

        $tags = $request->tags ?? [];

        // Delete existing tags
        $video->tags()->delete();

        $tagModels = [];
        $uniqueTags = [];
        
        foreach ($tags as $tagStr) {
            $tagStr = trim($tagStr);
            if (empty($tagStr)) continue;

            $normalized = \App\Services\SearchNormalizationService::normalizeEnglishAndRoman($tagStr) ?? strtolower($tagStr);
            
            if (in_array($normalized, $uniqueTags)) continue;
            
            $uniqueTags[] = $normalized;
            $tagModels[] = new \App\Models\VideoTag([
                'tag' => $tagStr,
                'normalized_tag' => $normalized,
            ]);
        }

        if (count($tagModels) > 0) {
            $video->tags()->saveMany($tagModels);
        }

        // Trigger a save on the video to update Typesense
        $video->touch();
        if ($video->shouldBeSearchable()) {
            $video->searchable();
        }

        return response()->json([
            'message' => 'Tags saved successfully',
            'tags' => collect($tagModels)->pluck('tag')->toArray(),
        ]);
    }
}
