<?php
namespace App\Services;

use Google\Client;
use Google\Service\YouTube;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use App\Models\User;

class GoogleApiService
{
    protected $user;
    protected $credentialOwner;
    protected $clientId;
    protected $clientSecret;
    protected $redirectUri;

    public function __construct(User $user)
    {
        $this->user = $user;
        // Resolve effective Google/YouTube credential owner based on role hierarchy
        $this->credentialOwner = $user->getGoogleCredentialOwner() ?? $user;
        $this->clientId = config('services.google.client_id') ?? env('GOOGLE_CLIENT_ID');
        $this->clientSecret = config('services.google.client_secret') ?? env('GOOGLE_CLIENT_SECRET');
        $this->redirectUri = config('services.google.redirect') ?? env('GOOGLE_REDIRECT_URI');
    }

    public function getAuthUrl()
    {
        $client = $this->getClient();
        $client->addScope(YouTube::YOUTUBE_UPLOAD);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        return $client->createAuthUrl();
    }

    public function handleCallback($code)
    {
        $client = $this->getClient();
        $token = $client->fetchAccessTokenWithAuthCode($code);
        if (isset($token['access_token'])) {
            $this->user->google_access_token = $token['access_token'];
        }
        if (isset($token['refresh_token'])) {
            $this->user->google_refresh_token = $token['refresh_token'];
        }
        if (isset($token['expires_in'])) {
            $this->user->google_token_expires_at = Carbon::now()->addSeconds($token['expires_in']);
        }
        $this->user->save();
        return $token;
    }

    public function getAccessToken()
    {
        if (!$this->credentialOwner->google_access_token || !$this->credentialOwner->google_token_expires_at || Carbon::now()->gte($this->credentialOwner->google_token_expires_at)) {
            $this->refreshAccessToken();
        }
        return $this->credentialOwner->google_access_token;
    }

    public function refreshAccessToken()
    {
        if (!$this->credentialOwner->google_refresh_token) {
            throw new \Exception('No Google refresh token available for user');
        }
        $client = $this->getClient();
        $client->refreshToken($this->credentialOwner->google_refresh_token);
        $token = $client->getAccessToken();
        if (isset($token['access_token'])) {
            $this->credentialOwner->google_access_token = $token['access_token'];
        }
        if (isset($token['expires_in'])) {
            $this->credentialOwner->google_token_expires_at = Carbon::now()->addSeconds($token['expires_in']);
        }
        $this->credentialOwner->save();
        return $token;
    }

    public function getClient()
    {
        $client = new Client();

        // Fix for XAMPP SSL timeout issues
        $client->setHttpClient(
            new \GuzzleHttp\Client([
                'verify' => false, // Disable SSL verification for local development
                'timeout' => 60,
                'connect_timeout' => 30,
            ])
        );

        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        if ($this->credentialOwner->google_access_token) {
            $client->setAccessToken([
                'access_token' => $this->credentialOwner->google_access_token,
                'refresh_token' => $this->credentialOwner->google_refresh_token,
                'expires_in' => Carbon::parse($this->credentialOwner->google_token_expires_at)->diffInSeconds(Carbon::now(), false),
            ]);
        }
        return $client;
    }

    public function getYoutubeService($client = null)
    {
        if (!$client) {
            $client = $this->getClient();
        }
        return new YouTube($client);
    }

    public function uploadVideo($videoPath, $title, $description, $tags = [], $privacyStatus = 'unlisted')
    {
        // Ensure we have a valid access token before attempting upload
        $this->getAccessToken();

        // IMPORTANT: Use the SAME client for both YouTube service and defer setting
        $client = $this->getClient();
        $youtube = $this->getYoutubeService($client);
        
        // Clean and validate title (YouTube max 100 chars)
        $cleanTitle = trim($title);
        if (empty($cleanTitle)) {
            $cleanTitle = 'Video ' . date('Y-m-d H:i:s');
        }
        if (strlen($cleanTitle) > 100) {
            $cleanTitle = substr($cleanTitle, 0, 97) . '...';
        }
        
        // Clean description
        $cleanDescription = trim($description);
        if (strlen($cleanDescription) > 5000) {
            $cleanDescription = substr($cleanDescription, 0, 4997) . '...';
        }
        
        \Illuminate\Support\Facades\Log::info("YouTube upload metadata", [
            'title' => $cleanTitle,
            'description_length' => strlen($cleanDescription),
            'tags' => $tags,
            'privacy' => $privacyStatus
        ]);
        
        $snippet = new YouTube\VideoSnippet();
        $snippet->setTitle($cleanTitle);
        $snippet->setDescription($cleanDescription);
        $snippet->setTags(is_array($tags) ? $tags : []);
        $snippet->setCategoryId('22'); // 22 = People & Blogs (required!)
        
        $status = new YouTube\VideoStatus();
        $status->setPrivacyStatus($privacyStatus);
        
        $video = new YouTube\Video();
        $video->setSnippet($snippet);
        $video->setStatus($status);
        
        // Get file size for resumable upload
        $fileSize = filesize($videoPath);
        
        // For small files (< 50MB), use simple upload
        if ($fileSize < 50 * 1024 * 1024) {
            \Illuminate\Support\Facades\Log::info("Using simple upload for small file", ['size_mb' => round($fileSize / 1024 / 1024, 2)]);
            $response = $youtube->videos->insert(
                'snippet,status',
                $video,
                [
                    'data' => file_get_contents($videoPath),
                    'mimeType' => 'video/mp4',
                    'uploadType' => 'multipart',
                ]
            );
            return $response;
        }
        
        // For large files, use resumable upload (streams in chunks, memory efficient)
        \Illuminate\Support\Facades\Log::info("Using resumable upload for large file", ['size_mb' => round($fileSize / 1024 / 1024, 2)]);
        
        // Set defer to true to get the request object instead of executing immediately
        $client->setDefer(true);
        
        $insertRequest = $youtube->videos->insert('snippet,status', $video);
        
        // Create MediaFileUpload for resumable upload
        // Chunk size must be a multiple of 256KB (262144 bytes)
        $chunkSizeBytes = 10 * 1024 * 1024; // 10MB chunks
        
        $media = new \Google\Http\MediaFileUpload(
            $client,
            $insertRequest,
            'video/mp4',
            null,
            true, // resumable
            $chunkSizeBytes
        );
        $media->setFileSize($fileSize);
        
        // Upload in chunks by reading from file
        $handle = fopen($videoPath, 'rb');
        $uploadStatus = false;
        $chunkNumber = 0;
        $totalChunks = ceil($fileSize / $chunkSizeBytes);
        
        while (!$uploadStatus && !feof($handle)) {
            $chunk = fread($handle, $chunkSizeBytes);
            $uploadStatus = $media->nextChunk($chunk);
            $chunkNumber++;
            
            // Log progress every few chunks
            if ($chunkNumber % 5 === 0 || $chunkNumber === $totalChunks) {
                $percent = round(($chunkNumber / $totalChunks) * 100, 1);
                \Illuminate\Support\Facades\Log::info("YouTube upload progress: {$percent}% (chunk {$chunkNumber}/{$totalChunks})");
            }
        }
        
        fclose($handle);
        
        // Reset defer back to false
        $client->setDefer(false);
        
        \Illuminate\Support\Facades\Log::info("YouTube upload completed");
        
        return $uploadStatus;
    }

    /**
     * Get the status of a YouTube video by its ID.
     * @param string $videoId
     * @return array|null
     */
    public function getVideoStatus($videoId)
    {
        // Ensure we have a valid access token before attempting to get status
        $this->getAccessToken();

        $youtube = $this->getYoutubeService();
        $response = $youtube->videos->listVideos('status', ['id' => $videoId]);
        if ($response && count($response) && isset($response[0])) {
            $status = $response[0]->getStatus();
            return [
                'upload_status' => $status->getUploadStatus(), // 'uploaded', 'processed', 'failed'
                'privacy_status' => $status->getPrivacyStatus(), // 'public', 'private', 'unlisted'
            ];
        }
        return null;
    }
}
