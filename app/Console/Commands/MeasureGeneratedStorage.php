<?php
namespace App\Console\Commands;
use App\Services\GeneratedMediaStorage;
use Illuminate\Console\Command;
class MeasureGeneratedStorage extends Command
{
    protected $signature = 'operations:measure-generated-storage';
    protected $description = 'Measure local generated processing media; preserve source and index audit snapshots';
    public function handle(): int
    {
        try {
            $measurement = GeneratedMediaStorage::measure(storage_path());
            $path = storage_path('app/private/operations/generated-media-v1.json');
            if (!is_dir(dirname($path))) mkdir(dirname($path), 0750, true);
            $temporary = tempnam(dirname($path), 'generated-media-');
            if ($temporary === false) throw new \RuntimeException('Cannot stage measurement');
            try {
                if (file_put_contents($temporary, json_encode($measurement, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT), LOCK_EX) === false) throw new \RuntimeException('Cannot save measurement');
                chmod($temporary, 0640);
                if (!rename($temporary, $path)) throw new \RuntimeException('Cannot publish measurement');
            } finally { if (is_file($temporary)) unlink($temporary); }
            $this->line(json_encode($measurement, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Measurement failed; previous snapshot retained: '.$exception->getMessage());
            return self::FAILURE;
        }
    }
}
