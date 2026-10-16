<?php
namespace App\Services;
final class GeneratedMediaStorage
{
    public static function measure(string $storageRoot): array
    {
        $bytes = 0; $files = 0; $copies = 0; $unclassified = 0; $scanned = 0;
        foreach (['temp', 'tmp'] as $folder) {
            $directory = $storageRoot.'/app/'.$folder;
            if (!is_dir($directory)) continue;
            if (!is_readable($directory)) throw new \RuntimeException('Media directory is unreadable');
            foreach (new \DirectoryIterator($directory) as $file) {
                if ($file->isDot() || $file->isLink() || !$file->isFile()) continue;
                if (++$scanned > 10000) throw new \RuntimeException('Media inventory exceeds bounded scan limit');
                $name = $file->getFilename(); $size = $file->getSize();
                // These output locations and names are defined by ProcessVideoJob
                // and DropboxService::convertAndUploadMtsToMp4, respectively.
                if (($folder === 'temp' && preg_match('/^audio_.*\.(mp3|webm)$/i', $name))
                    || ($folder === 'tmp' && strtolower($file->getExtension()) === 'mp4')) {
                    $bytes += $size; $files++;
                } elseif (($folder === 'temp' && str_starts_with($name, 'video_'))
                    || ($folder === 'tmp' && strtolower($file->getExtension()) === 'mts')) {
                    $copies += $size;
                } else {
                    $unclassified += $size;
                }
            }
        }
        return ['status'=>'measured_local', 'bytes'=>$bytes, 'files'=>$files,
            'downloaded_source_copy_bytes'=>$copies, 'unclassified_bytes'=>$unclassified,
            'observed_at_utc'=>gmdate(DATE_ATOM),
            'scope'=>'Local processing outputs in storage/app/temp and storage/app/tmp only; remote generated/proxy storage is not inventoried.'];
    }
}
