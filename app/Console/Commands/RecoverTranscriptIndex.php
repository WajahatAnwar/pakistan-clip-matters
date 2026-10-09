<?php
namespace App\Console\Commands;

use App\Jobs\GenerateVideoEmbedding;
use App\Models\Video;
use App\Services\TranscriptSegmentMapper;
use Illuminate\Console\Command;

class RecoverTranscriptIndex extends Command
{
    protected $signature = 'search:recover-transcripts {--id=* : Explicit video IDs} {--dry-run : Inspect without dispatching}';
    protected $description = 'Inspect or queue targeted transcript indexing; never retranscribes';
    public function handle(): int
    {
        $ids = $this->option('id');
        if (!$ids) { $this->error('Explicit --id values are required'); return self::FAILURE; }
        foreach ($ids as $id) {
            $video = Video::with('embedding')->find($id);
            if (!$video || !$video->shouldBeSearchable()) { $this->warn("{$id}: missing or ineligible"); continue; }
            try {
                $data = $video->speakers_data;
                for ($i = 0; is_string($data) && $i < 4; $i++) $data = json_decode($data, true);
                $segments = app(TranscriptSegmentMapper::class)->map($data ?? [], $video->transcript_timestamp_unit ?? ($video->transcript_id ? 'milliseconds' : 'unknown'));
                if (!$segments) throw new \RuntimeException('No valid transcript segments');
                $this->info("{$id}: ".count($segments).' valid segments; embedding='.($video->embedding?->status ?? 'missing'));
                if (!$this->option('dry-run')) GenerateVideoEmbedding::dispatch($video->id);
            } catch (\Throwable $e) { $this->warn("{$id}: ".$e->getMessage()); }
        }
        return self::SUCCESS;
    }
}
