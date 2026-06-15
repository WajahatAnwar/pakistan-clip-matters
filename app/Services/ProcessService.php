<?php
namespace App\Services;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ProcessService
{
    /**
     * Run a shell command and return output or throw exception on failure.
     *
     * @param array $command
     * @param int $timeout
     * @return string
     * @throws ProcessFailedException
     */
    public function run(array $command, int $timeout = 300): string
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }

    /**
     * Download and convert YouTube audio to mp3 using yt-dlp.
     *
     * @param string $youtubeUrl
     * @param string $outputFile
     * @return string Path to the output file
     */
    public function downloadYoutubeAudio(string $youtubeUrl, string $outputFile, int $retries = 3, int $waitSeconds = 30): string
    {
        // Try direct yt-dlp command first, fallback to Python module if not found
        $ytDlpCommand = $this->getYtDlpCommand();

        // Change output file extension to match the downloaded format (webm/opus)
        // AssemblyAI accepts various audio formats, so no conversion needed
        $outputFile = preg_replace('/\.(mp3|wav|m4a)$/i', '.webm', $outputFile);

        $command = array_merge(
                $ytDlpCommand,
                [
                    '-f', 'bestaudio',
                    '-o', $outputFile,
                    $youtubeUrl,
                ]
            );;

        // Retry logic for "video still processing" errors
        $lastException = null;
        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            try {
                $this->run($command, 600); // 10 minute timeout for audio download
                return $outputFile;
            } catch (\Exception $e) {
                $lastException = $e;
                $errorMessage = $e->getMessage();

                // Check if it's a "processing" or "captcha" error
                if (
                    str_contains($errorMessage, 'We\'re processing this video') ||
                    str_contains($errorMessage, 'Check back later')
                ) {

                    if ($attempt < $retries) {
                        \Log::info("YouTube video still processing, waiting {$waitSeconds} seconds before retry {$attempt}/{$retries}...");
                        sleep($waitSeconds);
                        continue;
                    }
                }

                // For other errors, don't retry
                throw $e;
            }
        }

        // All retries failed
        throw $lastException;
    }

    /**
     * Get the yt-dlp command based on the system configuration.
     *
     * @return array
     */
    protected function getYtDlpCommand(): array
{
    // Path to your manually installed binary
    $binary = base_path('yt-dlp');
    \Log::info("Checking for yt-dlp binary at: {$binary}");

    if (file_exists($binary)) {
        return [$binary];
    }

    // If binary not found, fallback to PATH
    $process = new Process(['yt-dlp', '--version']);
    $process->run();

    if ($process->isSuccessful()) {
        return ['yt-dlp'];
    }

    throw new \Exception("yt-dlp not found on Cloudways. Install with curl.");
}

    /**
     * Run FFmpeg command for video/audio processing.
     *
     * @param array $args
     * @return string
     */
    public function runFfmpeg(array $args): string
    {
        $command = array_merge(['ffmpeg'], $args);
        return $this->run($command);
    }
}
