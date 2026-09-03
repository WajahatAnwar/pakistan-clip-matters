<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Video;
use App\Jobs\ProcessVideoJob;

$videos = Video::where('language_detected', 'hi')->get();

if ($videos->isEmpty()) {
    echo "No videos found with Hindi transcription.\n";
    exit;
}

echo "Found " . $videos->count() . " videos with Hindi transcription.\n";
echo "Queueing for reprocessing...\n";

foreach ($videos as $video) {
    // Reset video status
    $video->processing_status = 'pending';
    $video->processing_error = null;
    $video->save();

    // Dispatch the processing job again
    ProcessVideoJob::dispatch(
        $video->user_id,
        $video->id,
        $video->dropbox_path,
        $video->title,
        $video->description
    );
    
    echo "Dispatched Video ID: {$video->id} ({$video->title})\n";
}

echo "All Hindi videos have been queued for reprocessing!\n";
