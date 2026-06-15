/**
 * Video Helper Utilities
 * 
 * Collection of helper functions for video processing and display
 */

/**
 * Extract YouTube video ID from various URL formats
 * 
 * Supported formats:
 * - https://www.youtube.com/watch?v=VIDEO_ID
 * - https://youtu.be/VIDEO_ID
 * - https://www.youtube.com/embed/VIDEO_ID
 * - https://www.youtube.com/v/VIDEO_ID
 * 
 * @param {string} url - YouTube video URL
 * @returns {string|null} Video ID or null if not found
 */
export const getYoutubeId = (url) => {
    if (!url) return null;
    
    const patterns = [
        /(?:https?:\/\/)?(?:www\.)?youtube\.com\/watch\?v=([^\s&]+)/,
        /(?:https?:\/\/)?(?:www\.)?youtu\.be\/([^\s&]+)/,
        /(?:https?:\/\/)?(?:www\.)?youtube\.com\/embed\/([^\s&]+)/,
        /(?:https?:\/\/)?(?:www\.)?youtube\.com\/v\/([^\s&]+)/
    ];
    
    for (const pattern of patterns) {
        const match = url.match(pattern);
        if (match && match[1]) {
            return match[1];
        }
    }
    
    return null;
};

/**
 * Get YouTube thumbnail URL from video URL
 * 
 * Thumbnail qualities available:
 * - default: 120x90
 * - mqdefault: 320x180
 * - hqdefault: 480x360
 * - sddefault: 640x480
 * - maxresdefault: 1280x720 (not always available)
 * 
 * @param {string} url - YouTube video URL
 * @param {string} quality - Thumbnail quality (default, mq, hq, sd, maxres)
 * @returns {string|null} Thumbnail URL or null if video ID not found
 */
export const getYoutubeThumbnail = (url, quality = 'hq') => {
    const videoId = getYoutubeId(url);
    if (!videoId) return null;
    
    const qualityMap = {
        'default': 'default',
        'mq': 'mqdefault',
        'hq': 'hqdefault',
        'sd': 'sddefault',
        'maxres': 'maxresdefault'
    };
    
    const thumbnailQuality = qualityMap[quality] || 'hqdefault';
    return `https://img.youtube.com/vi/${videoId}/${thumbnailQuality}.jpg`;
};

/**
 * Format video duration from seconds to HH:MM:SS or MM:SS
 * 
 * @param {number} seconds - Duration in seconds
 * @returns {string} Formatted duration
 */
export const formatDuration = (seconds) => {
    if (!seconds || seconds < 0) return '00:00';
    
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);
    
    if (hours > 0) {
        return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }
    
    return `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
};

/**
 * Calculate video processing completion percentage
 * 
 * @param {object} video - Video object with processing flags
 * @returns {number} Percentage (0-100)
 */
export const calculateProcessingPercentage = (video) => {
    if (!video) return 0;
    
    let completed = 0;
    const hasYoutube = !!(video.youtube_url || video.youtube_video_id || video.onYoutube);
    const steps = hasYoutube
        ? ['onDropbox', 'onYoutube', 'isTranscript', 'isTagged', 'hasEmbedding']
        : ['onDropbox', 'isTranscript', 'isTagged', 'hasEmbedding'];
    
    steps.forEach(step => {
        if (video[step]) completed++;
    });
    
    return (completed / steps.length) * 100;
};

/**
 * Get processing step status
 * 
 * @param {object} video - Video object
 * @returns {object} Status object with step details
 */
export const getProcessingStatus = (video) => {
    if (!video) {
        return {
            currentStep: 'Not Started',
            completedSteps: 0,
            totalSteps: 5,
            percentage: 0,
            isComplete: false,
            failedStep: null,
            hasYoutube: true
        };
    }
    
    // YouTube step is optional — only include it if the video has a YouTube URL/ID
    const hasYoutube = !!(video.youtube_url || video.youtube_video_id || video.onYoutube);

    const steps = hasYoutube
        ? [
            { key: 'onDropbox', label: 'Dropbox Upload' },
            { key: 'onYoutube', label: 'YouTube Upload' },
            { key: 'isTranscript', label: 'Transcription' },
            { key: 'isTagged', label: 'Speaker Tagging' },
            { key: 'hasEmbedding', label: 'Search Ready' }
        ]
        : [
            { key: 'onDropbox', label: 'Dropbox Upload' },
            { key: 'isTranscript', label: 'Transcription' },
            { key: 'isTagged', label: 'Speaker Tagging' },
            { key: 'hasEmbedding', label: 'Search Ready' }
        ];
    
    let completedSteps = 0;
    let currentStep = 'Not Started';
    let failedStep = null;
    
    // If processing status is failed, detect which step failed
    if (video.processing_status === 'failed' || video.status === 'failed') {
        for (let i = 0; i < steps.length; i++) {
            if (video[steps[i].key]) {
                completedSteps++;
            } else {
                failedStep = i;
                currentStep = `Failed at ${steps[i].label}`;
                break;
            }
        }
    } else {
        // Normal processing flow
        for (let i = 0; i < steps.length; i++) {
            if (video[steps[i].key]) {
                completedSteps++;
            } else {
                currentStep = steps[i].label;
                break;
            }
        }
        
        if (completedSteps === steps.length) {
            currentStep = 'Completed';
        }
    }
    
    return {
        currentStep,
        completedSteps,
        totalSteps: steps.length,
        percentage: (completedSteps / steps.length) * 100,
        isComplete: completedSteps === steps.length,
        failedStep,
        hasYoutube
    };
};

/**
 * Format file size from bytes to human-readable format
 * 
 * @param {number} bytes - File size in bytes
 * @returns {string} Formatted file size
 */
export const formatFileSize = (bytes) => {
    if (!bytes || bytes === 0) return '0 B';
    
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const k = 1024;
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${units[i]}`;
};

/**
 * Get Dropbox file thumbnail URL
 * Note: This requires actual Dropbox API integration
 * 
 * @param {string} dropboxPath - Dropbox file path
 * @returns {string} Thumbnail URL
 */
export const getDropboxThumbnail = (dropboxPath) => {
    // This is a placeholder. Actual implementation would use Dropbox API
    return `https://www.dropbox.com/thumbnail${dropboxPath}`;
};

/**
 * Validate video URL (YouTube or direct video file)
 * 
 * @param {string} url - URL to validate
 * @returns {object} Validation result
 */
export const validateVideoUrl = (url) => {
    if (!url) {
        return { isValid: false, type: null, error: 'URL is required' };
    }
    
    // Check if it's a YouTube URL
    if (getYoutubeId(url)) {
        return { isValid: true, type: 'youtube', error: null };
    }
    
    // Check if it's a direct video file URL
    const videoExtensions = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'flv'];
    const extension = url.split('.').pop().toLowerCase();
    
    if (videoExtensions.includes(extension)) {
        return { isValid: true, type: 'direct', error: null };
    }
    
    return { isValid: false, type: null, error: 'Invalid video URL' };
};

/**
 * Format timestamp to readable date
 * 
 * @param {string} timestamp - ISO timestamp
 * @param {boolean} includeTime - Include time in output
 * @returns {string} Formatted date
 */
export const formatTimestamp = (timestamp, includeTime = false) => {
    if (!timestamp) return 'N/A';
    
    const date = new Date(timestamp);
    
    if (includeTime) {
        return date.toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }
    
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
};

/**
 * Get video status color based on processing status
 * 
 * @param {string} status - Processing status
 * @returns {string} Color code
 */
export const getStatusColor = (status) => {
    const colors = {
        'pending': '#6B7280',      // Gray
        'processing': '#F59E0B',   // Orange
        'completed': '#22C55E',    // Green
        'failed': '#EF4444',       // Red
        'paused': '#8B5CF6'        // Purple
    };
    
    return colors[status] || colors.pending;
};

/**
 * Generate video processing steps data
 * 
 * @param {object} video - Video object
 * @returns {array} Array of step objects
 */
export const getVideoProcessingSteps = (video) => {
    return [
        {
            key: 'dropbox',
            label: 'Dropbox Upload',
            completed: video?.onDropbox || false,
            icon: 'DropBox'
        },
        {
            key: 'youtube',
            label: 'YouTube Upload',
            completed: video?.onYoutube || false,
            icon: 'Youtube'
        },
        {
            key: 'transcript',
            label: 'Transcription',
            completed: video?.isTranscript || false,
            icon: 'Transcript',
            metadata: {
                language: video?.language_detected,
                transcriptId: video?.transcript_id
            }
        },
        {
            key: 'tagging',
            label: 'Speaker Tagging',
            completed: video?.isTagged || false,
            icon: 'Speaker',
            metadata: {
                jobId: video?.pyannote_job_id
            }
        }
    ];
};

export default {
    getYoutubeId,
    getYoutubeThumbnail,
    formatDuration,
    calculateProcessingPercentage,
    getProcessingStatus,
    formatFileSize,
    getDropboxThumbnail,
    validateVideoUrl,
    formatTimestamp,
    getStatusColor,
    getVideoProcessingSteps
};
