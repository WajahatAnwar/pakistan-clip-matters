<?php

namespace App\Console\Commands;

use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CleanupQdrant extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'qdrant:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deletes orphaned videos from Qdrant that no longer exist in the MySQL database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Fetching valid video IDs from database...");
        $validVideoIds = Video::pluck('id')->toArray();
        $this->info("Found " . count($validVideoIds) . " valid videos.");

        $pythonServiceUrl = config('services.embedding.url', 'https://web-production-935af.up.railway.app');
        $apiKey = config('services.embedding.api_key', '');

        $this->info("Calling Python service to clean up Qdrant...");

        $response = Http::timeout(120)->withHeaders([
            'X-API-Key' => $apiKey,
            'Content-Type' => 'application/json',
        ])->post("{$pythonServiceUrl}/cleanup-orphan-videos", [
            'valid_video_ids' => $validVideoIds
        ]);

        if ($response->successful()) {
            $this->info("Success: " . $response->json('message'));
        } else {
            $this->error("Failed to clean up Qdrant: " . $response->body());
        }
    }
}
