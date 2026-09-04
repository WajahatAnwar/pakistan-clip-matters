<?php

return [
    'queue' => env('VIDEO_PROCESSING_QUEUE', 'default'),
    'stream_from_dropbox' => (bool) env('VIDEO_STREAM_FROM_DROPBOX', true),

    // Includes remote probing, audio extraction, and transcription polling.
    // The queue connection's retry_after must be greater than this value.
    'timeout' => (int) env('VIDEO_PROCESSING_TIMEOUT', 21600),
    'ffmpeg_timeout' => (int) env('VIDEO_FFMPEG_TIMEOUT', 10800),
    'stale_after_hours' => (float) env('VIDEO_PROCESSING_STALE_AFTER_HOURS', 12),
];
