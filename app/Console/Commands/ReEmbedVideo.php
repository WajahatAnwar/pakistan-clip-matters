<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Video;
use Illuminate\Support\Facades\Log;

class ReEmbedVideo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'video:re-embed {video_id?} {--all}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-generates Qdrant embeddings for videos with existing transcripts using updated granular word-mapping logic.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $videoId = $this->argument('video_id');
        $all = $this->option('all');

        if (!$videoId && !$all) {
            $this->error('Please provide a video_id or use the --all flag.');
            return;
        }

        $videos = $videoId ? Video::where('id', $videoId)->get() : Video::whereNotNull('speakers_data')->get();

        $this->info("Found " . $videos->count() . " videos to process.");

        foreach ($videos as $video) {
            $this->info("Dispatching embeddings job for video {$video->id}...");
            \App\Jobs\GenerateVideoEmbedding::dispatch($video)->onQueue('high');
            $this->info("Successfully dispatched video {$video->id}.");
        }
    }
}

