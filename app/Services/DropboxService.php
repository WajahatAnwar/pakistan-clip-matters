<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * Helper class to wrap Dropbox API entries in a Flysystem-compatible interface
 */
class DropboxFileInfo
{
    protected $entry;

    public function __construct(array $entry)
    {
        $this->entry = $entry;
    }

    public function isFile(): bool
    {
        return ($this->entry['.tag'] ?? '') === 'file';
    }

    public function isDir(): bool
    {
        return ($this->entry['.tag'] ?? '') === 'folder';
    }

    public function path(): string
    {
        return $this->entry['path_display'] ?? $this->entry['path_lower'] ?? '';
    }

    public function name(): string
    {
        return $this->entry['name'] ?? basename($this->path());
    }
    
    public function size(): ?int
    {
        return $this->entry['size'] ?? null;
    }
}

/**
 * Dropbox Service with Business/Team Account Support
 * 
 * For Dropbox Business accounts, API requests require:
 * - Dropbox-API-Select-User header: Team member ID to act as
 * - Dropbox-API-Path-Root header: Root namespace for file paths
 */
class DropboxService
{
    protected $user;
    protected $credentialOwner;
    protected $clientId;
    protected $clientSecret;
    protected $redirectUri;
    
    // Team account info (cached after first detection)
    protected $isTeamAccount = null;
    protected $teamMemberId = null;
    protected $rootNamespaceId = null;

    public function __construct(User $user)
    {
        $this->user = $user;
        // Resolve effective Dropbox credential owner (always Super Admin for non-superAdmins)
        $this->credentialOwner = $user->getDropboxCredentialOwner() ?? $user;
        $this->clientId = config('services.dropbox.client_id');
        $this->clientSecret = config('services.dropbox.client_secret');
        $this->redirectUri = config('services.dropbox.redirect');
    }

    public function getAuthUrl()
    {
        $query = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'token_access_type' => 'offline',
            // Request team scopes for Business accounts
            'scope' => 'account_info.read files.metadata.read files.metadata.write files.content.read files.content.write sharing.read team_info.read team_data.member team_data.content.read files.team_metadata.read',
        ]);
        return "https://www.dropbox.com/oauth2/authorize?$query";
    }

    public function handleCallback($code)
    {
        $response = Http::timeout(60)
            ->withOptions(['verify' => false, 'connect_timeout' => 30])
            ->asForm()
            ->post('https://api.dropboxapi.com/oauth2/token', [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $this->redirectUri,
            ]);
        
        $data = $response->json();
        Log::info('Dropbox OAuth Response: ' . json_encode($data));
        
        if (isset($data['access_token'])) {
            $this->user->dropbox_access_token = $data['access_token'];
        }
        if (isset($data['refresh_token'])) {
            $this->user->dropbox_refresh_token = $data['refresh_token'];
        }
        if (isset($data['expires_in'])) {
            $this->user->dropbox_token_expires_at = Carbon::now()->addSeconds($data['expires_in']);
        }
        
        // Check if this is a team token and store team info
        if (isset($data['team_id'])) {
            $this->user->dropbox_team_id = $data['team_id'];
            
            // Try to get and store the first active team member ID
            $this->user->save();
            $this->detectTeamAccount();
            
            if ($this->teamMemberId) {
                $this->user->dropbox_team_member_id = $this->teamMemberId;
            }
            if ($this->rootNamespaceId) {
                $this->user->dropbox_root_namespace_id = $this->rootNamespaceId;
            }
        }
        
        $this->user->save();
        return $data;
    }

    public function getAccessToken()
    {
        if (!$this->credentialOwner->dropbox_access_token || 
            !$this->credentialOwner->dropbox_token_expires_at || 
            Carbon::now()->gte($this->credentialOwner->dropbox_token_expires_at)) {
            $this->refreshAccessToken();
        }
        return $this->credentialOwner->dropbox_access_token;
    }

    public function refreshAccessToken()
    {
        if (!$this->credentialOwner->dropbox_refresh_token) {
            throw new \Exception('No Dropbox refresh token available for user');
        }
        
        $response = Http::timeout(60)
            ->withOptions(['verify' => false, 'connect_timeout' => 30])
            ->asForm()
            ->post('https://api.dropboxapi.com/oauth2/token', [
                'refresh_token' => $this->credentialOwner->dropbox_refresh_token,
                'grant_type' => 'refresh_token',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);
        
        $data = $response->json();
        
        if (isset($data['access_token'])) {
            $this->credentialOwner->dropbox_access_token = $data['access_token'];
        }
        if (isset($data['expires_in'])) {
            $this->credentialOwner->dropbox_token_expires_at = Carbon::now()->addSeconds($data['expires_in']);
        }
        $this->credentialOwner->save();
        
        return $data;
    }

    /**
     * Detect if this is a team account and get required IDs
     */
    protected function detectTeamAccount()
    {
        if ($this->isTeamAccount !== null) {
            return; // Already detected
        }
        
        $token = $this->getAccessToken();
        
        // Check if we have stored team info
        if ($this->credentialOwner->dropbox_team_member_id && $this->credentialOwner->dropbox_root_namespace_id) {
            $this->isTeamAccount = true;
            $this->teamMemberId = $this->credentialOwner->dropbox_team_member_id;
            $this->rootNamespaceId = $this->credentialOwner->dropbox_root_namespace_id;
            Log::info("Using stored team account info", [
                'member_id' => $this->teamMemberId,
                'namespace_id' => $this->rootNamespaceId
            ]);
            return;
        }
        
        // Try to get team members (this will only work for team tokens)
        try {
            $response = Http::withToken($token)
                ->withOptions(['verify' => false])
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('https://api.dropboxapi.com/2/team/members/list_v2', [
                    'limit' => 10,
                    'include_removed' => false
                ]);
            
            if ($response->successful()) {
                $members = $response->json()['members'] ?? [];
                
                // Get first active member (preferably admin)
                $activeMember = collect($members)->first(function($m) {
                    return ($m['profile']['status']['.tag'] ?? '') === 'active';
                });
                
                if ($activeMember) {
                    $this->isTeamAccount = true;
                    $this->teamMemberId = $activeMember['profile']['team_member_id'];
                    
                    Log::info("Detected team account, member: " . $this->teamMemberId);
                    
                    // Get the member's root namespace
                    $this->detectRootNamespace();
                    
                    // Store for future use
                    $this->credentialOwner->dropbox_team_member_id = $this->teamMemberId;
                    if ($this->rootNamespaceId) {
                        $this->credentialOwner->dropbox_root_namespace_id = $this->rootNamespaceId;
                    }
                    $this->credentialOwner->save();
                    
                    return;
                }
            }
        } catch (\Exception $e) {
            Log::debug("Not a team account or team API failed: " . $e->getMessage());
        }
        
        $this->isTeamAccount = false;
    }
    
    /**
     * Get the root namespace ID for file operations
     */
    protected function detectRootNamespace()
    {
        if (!$this->teamMemberId) return;
        
        $token = $this->getAccessToken();
        
        try {
            $response = Http::withToken($token)
                ->withOptions(['verify' => false])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Dropbox-API-Select-User' => $this->teamMemberId
                ])
                ->send('POST', 'https://api.dropboxapi.com/2/users/get_current_account', [
                    'body' => 'null'
                ]);
            
            if ($response->successful()) {
                $data = $response->json();
                $this->rootNamespaceId = $data['root_info']['root_namespace_id'] ?? null;
                Log::info("Detected root namespace: " . $this->rootNamespaceId);
            }
        } catch (\Exception $e) {
            Log::warning("Could not get root namespace: " . $e->getMessage());
        }
    }
    
    /**
     * Get headers required for API calls (handles team accounts)
     */
    public function getApiHeaders(): array
    {
        $this->detectTeamAccount();
        
        $headers = ['Content-Type' => 'application/json'];
        
        if ($this->isTeamAccount && $this->teamMemberId) {
            $headers['Dropbox-API-Select-User'] = $this->teamMemberId;
            
            if ($this->rootNamespaceId) {
                $headers['Dropbox-API-Path-Root'] = json_encode([
                    '.tag' => 'root',
                    'root' => $this->rootNamespaceId
                ]);
            }
        }
        
        return $headers;
    }

    /**
     * List files from Dropbox (supports both personal and team accounts)
     */
    public function listFiles($path = '', $recursive = false)
    {
        $token = $this->getAccessToken();
        $headers = $this->getApiHeaders();
        
        Log::info("Listing Dropbox files", [
            'path' => $path,
            'recursive' => $recursive,
            'is_team' => $this->isTeamAccount,
            'member_id' => $this->teamMemberId ?? 'N/A',
            'namespace_id' => $this->rootNamespaceId ?? 'N/A'
        ]);
        
        try {
            $response = Http::withToken($token)
                ->withOptions(['verify' => false])
                ->withHeaders($headers)
                ->post('https://api.dropboxapi.com/2/files/list_folder', [
                    'path' => $path ?: '',
                    'recursive' => $recursive,
                    'include_mounted_folders' => true,
                    'include_non_downloadable_files' => false
                ]);
            
            if (!$response->successful()) {
                Log::error("Dropbox list_folder failed", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return collect([]);
            }
            
            $data = $response->json();
            $entries = $data['entries'] ?? [];
            
            // Handle pagination (has_more)
            while (isset($data['has_more']) && $data['has_more'] && isset($data['cursor'])) {
                $continueResponse = Http::withToken($token)
                    ->withOptions(['verify' => false])
                    ->withHeaders($headers)
                    ->post('https://api.dropboxapi.com/2/files/list_folder/continue', [
                        'cursor' => $data['cursor']
                    ]);
                
                if ($continueResponse->successful()) {
                    $data = $continueResponse->json();
                    $entries = array_merge($entries, $data['entries'] ?? []);
                } else {
                    break;
                }
            }
            
            Log::info("Dropbox list_folder success", ['entries_count' => count($entries)]);
            
            return collect($entries)->map(function ($entry) {
                return new DropboxFileInfo($entry);
            });
            
        } catch (\Exception $e) {
            Log::error("Dropbox listFiles exception", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return collect([]);
        }
    }

    /**
     * Get a temporary download link for a file
     */
    public function getTemporaryLink($filePath)
    {
        $token = $this->getAccessToken();
        $headers = $this->getApiHeaders();
        
        Log::info("Dropbox getTemporaryLink starting", [
            'filePath' => $filePath,
            'hasToken' => !empty($token),
            'headers' => array_keys($headers)
        ]);
        
        try {
            $response = Http::withToken($token)
                ->withOptions(['verify' => false])
                ->withHeaders($headers)
                ->post('https://api.dropboxapi.com/2/files/get_temporary_link', [
                    'path' => $filePath
                ]);
            
            Log::info("Dropbox API response", [
                'filePath' => $filePath,
                'status' => $response->status(),
                'successful' => $response->successful()
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                $link = $data['link'] ?? null;
                
                Log::info("Dropbox link retrieved", [
                    'filePath' => $filePath,
                    'hasLink' => !empty($link),
                    'metadata' => $data['metadata'] ?? null
                ]);
                
                return $link;
            }
            
            Log::error("Dropbox get_temporary_link failed", [
                'filePath' => $filePath,
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return null;
            
        } catch (\Exception $e) {
            Log::error("Dropbox getTemporaryLink exception", [
                'filePath' => $filePath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get or create a shared link for a file that opens in Dropbox's web viewer
     * This is useful for video formats that browsers can't play directly
     */
    public function getSharedLink($filePath)
    {
        $token = $this->getAccessToken();
        $headers = $this->getApiHeaders();
        
        Log::info("Dropbox getSharedLink starting", [
            'filePath' => $filePath
        ]);
        
        try {
            // First, try to get existing shared links
            $response = Http::withToken($token)
                ->withOptions(['verify' => false])
                ->withHeaders($headers)
                ->post('https://api.dropboxapi.com/2/sharing/list_shared_links', [
                    'path' => $filePath,
                    'direct_only' => true
                ]);
            
            if ($response->successful()) {
                $data = $response->json();
                $links = $data['links'] ?? [];
                
                if (!empty($links)) {
                    // Return existing shared link - convert to preview URL
                    $link = $links[0]['url'] ?? null;
                    if ($link) {
                        // Convert ?dl=0 to ?raw=1 for direct access or keep as preview
                        $previewLink = str_replace('?dl=0', '?dl=0', $link);
                        Log::info("Dropbox existing shared link found", [
                            'filePath' => $filePath,
                            'link' => $previewLink
                        ]);
                        return $previewLink;
                    }
                }
            }
            
            // No existing link, create a new one
            $response = Http::withToken($token)
                ->withOptions(['verify' => false])
                ->withHeaders($headers)
                ->post('https://api.dropboxapi.com/2/sharing/create_shared_link_with_settings', [
                    'path' => $filePath,
                    'settings' => [
                        'requested_visibility' => 'public',
                        'audience' => 'public',
                        'access' => 'viewer'
                    ]
                ]);
            
            if ($response->successful()) {
                $data = $response->json();
                $link = $data['url'] ?? null;
                
                Log::info("Dropbox shared link created", [
                    'filePath' => $filePath,
                    'link' => $link
                ]);
                
                return $link;
            }
            
            // Check if error is "shared link already exists"
            $errorBody = $response->json();
            if (isset($errorBody['error']['.tag']) && $errorBody['error']['.tag'] === 'shared_link_already_exists') {
                // Re-fetch existing links
                $existingResponse = Http::withToken($token)
                    ->withOptions(['verify' => false])
                    ->withHeaders($headers)
                    ->post('https://api.dropboxapi.com/2/sharing/list_shared_links', [
                        'path' => $filePath,
                        'direct_only' => true
                    ]);
                
                if ($existingResponse->successful()) {
                    $existingData = $existingResponse->json();
                    $links = $existingData['links'] ?? [];
                    if (!empty($links)) {
                        return $links[0]['url'] ?? null;
                    }
                }
            }
            
            Log::error("Dropbox create_shared_link failed", [
                'filePath' => $filePath,
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return null;
            
        } catch (\Exception $e) {
            Log::error("Dropbox getSharedLink exception", [
                'filePath' => $filePath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Download file content as a string
     */
    public function readStream($filePath)
    {
        $token = $this->getAccessToken();
        
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Dropbox-API-Arg' => json_encode(['path' => $filePath])
        ];
        
        // Add team headers if needed
        $this->detectTeamAccount();
        if ($this->isTeamAccount && $this->teamMemberId) {
            $headers['Dropbox-API-Select-User'] = $this->teamMemberId;
            
            // CRITICAL: Also need Path-Root for team accounts
            if ($this->rootNamespaceId) {
                $headers['Dropbox-API-Path-Root'] = json_encode([
                    '.tag' => 'root',
                    'root' => $this->rootNamespaceId
                ]);
            }
        }
        
        Log::info("Dropbox download attempt", [
            'path' => $filePath,
            'is_team' => $this->isTeamAccount,
            'has_path_root' => isset($headers['Dropbox-API-Path-Root'])
        ]);
        
        try {
            $response = Http::withOptions(['verify' => false, 'timeout' => 600])
                ->withHeaders($headers)
                ->get('https://content.dropboxapi.com/2/files/download');
            
            if ($response->successful()) {
                Log::info("Dropbox download successful", ['size' => strlen($response->body())]);
                return $response->body();
            }
            
            Log::error("Dropbox download failed", [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500)
            ]);
            return null;
            
        } catch (\Exception $e) {
            Log::error("Dropbox readStream exception: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if connected to a team/business account
     */
    public function isTeamAccount(): bool
    {
        $this->detectTeamAccount();
        return $this->isTeamAccount === true;
    }
    
    /**
     * Get team member ID (for team accounts)
     */
    public function getTeamMemberId(): ?string
    {
        $this->detectTeamAccount();
        return $this->teamMemberId;
    }
    
    /**
     * Get root namespace ID (for team accounts)
     */
    public function getRootNamespaceId(): ?string
    {
        $this->detectTeamAccount();
        return $this->rootNamespaceId;
    }
    
    /**
     * Download file directly to local path using streaming (memory efficient for large files)
     * For team accounts, uses direct download API. For personal accounts, can use temporary link.
     * 
     * @param string $dropboxPath Path to file in Dropbox
     * @param string $localPath Local path to save the file
     * @param int|null $expectedFileSize Known remote size, to avoid a duplicate metadata request
     * @return bool True if download succeeded
     */
    public function downloadToFile(string $dropboxPath, string $localPath, ?int $expectedFileSize = null): bool
    {
        Log::info("Starting streamed download", ['dropbox_path' => $dropboxPath, 'local_path' => $localPath]);
        
        // Ensure directory exists
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        
        // Detect team account status
        $this->detectTeamAccount();

        // Refuse downloads that cannot fit before writing a large partial file.
        // Keep additional room for extracted audio and normal application use.
        $reserveBytes = max(0, (int) config('services.dropbox.temp_disk_reserve_mb', 5120)) * 1024 * 1024;
        $remoteFileSize = $expectedFileSize ?? $this->getFileSize($dropboxPath);
        if ($remoteFileSize !== null) {
            $freeBytes = disk_free_space($dir);
            $overheadPercent = max(0, (int) config('services.dropbox.temp_disk_overhead_percent', 10));
            $requiredBytes = (int) ceil($remoteFileSize * (1 + ($overheadPercent / 100))) + $reserveBytes;

            if ($freeBytes === false || $freeBytes < $requiredBytes) {
                Log::warning('Dropbox download skipped: insufficient temporary disk space', [
                    'path' => $dropboxPath,
                    'file_size_bytes' => $remoteFileSize,
                    'free_bytes' => $freeBytes,
                    'required_bytes' => $requiredBytes,
                ]);

                throw new \RuntimeException(sprintf(
                    'Insufficient temporary disk space: %.2f GB free, %.2f GB required',
                    ($freeBytes === false ? 0 : $freeBytes) / 1024 / 1024 / 1024,
                    $requiredBytes / 1024 / 1024 / 1024
                ));
            }
        }
        
        // For team accounts, we MUST use direct download API (temporary links don't work)
        // For personal accounts, we can also use direct download (it's more reliable)
        $token = $this->getAccessToken();
        
        // Prepare Dropbox-API-Arg header (required for /download endpoint)
        $apiArg = json_encode(['path' => $dropboxPath]);
        
        // Build headers - Must explicitly disable Content-Type to prevent cURL from adding default
        $curlHeaders = [
            'Authorization: Bearer ' . $token,
            'Dropbox-API-Arg: ' . $apiArg,
            'Content-Type:', // Empty Content-Type to override cURL's default application/x-www-form-urlencoded
        ];
        
        // Add team account headers if needed
        if ($this->isTeamAccount && $this->teamMemberId) {
            $curlHeaders[] = 'Dropbox-API-Select-User: ' . $this->teamMemberId;
            
            if ($this->rootNamespaceId) {
                $pathRoot = json_encode([
                    '.tag' => 'root',
                    'root' => $this->rootNamespaceId
                ]);
                $curlHeaders[] = 'Dropbox-API-Path-Root: ' . $pathRoot;
            }
            
            Log::info("Using team account for download", [
                'member_id' => $this->teamMemberId,
                'namespace_id' => $this->rootNamespaceId,
                'path' => $dropboxPath
            ]);
        }
        
        // Create a small temporary file for cURL diagnostics. Never use the
        // downloaded media file itself as an error buffer.
        $errorFile = tempnam(sys_get_temp_dir(), 'dropbox_error_');
        
        // Use cURL to stream directly to file (memory efficient)
        $fp = fopen($localPath, 'wb');
        if (!$fp) {
            Log::error("Could not open local file for writing", ['path' => $localPath]);
            if ($errorFile !== false) {
                @unlink($errorFile);
            }
            return false;
        }

        $errorHandle = $errorFile !== false ? fopen($errorFile, 'wb') : false;
        
        $ch = curl_init('https://content.dropboxapi.com/2/files/download');
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, null); // No body for download
        curl_setopt($ch, CURLOPT_FILE, $fp);
        if ($errorHandle !== false) {
            curl_setopt($ch, CURLOPT_STDERR, $errorHandle);
            curl_setopt($ch, CURLOPT_VERBOSE, true);
        }
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $largeFileThresholdBytes = max(1, (int) config('video-processing.large_file_threshold_mb', 10240)) * 1024 * 1024;
        $downloadTimeout = $remoteFileSize !== null && $remoteFileSize >= $largeFileThresholdBytes
            ? (int) config('services.dropbox.large_download_timeout', 10800)
            : (int) config('services.dropbox.download_timeout', 3300);

        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(60, $downloadTimeout));
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        $diskSpaceAbort = false;
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($dir, $reserveBytes, &$diskSpaceAbort) {
            static $lastLog = 0;
            static $lastDiskCheck = 0;
            $now = time();

            // Protect the application even if Dropbox metadata preflight was
            // unavailable or other processes consume disk during the download.
            if (($now - $lastDiskCheck) >= 5) {
                clearstatcache(true, $dir);
                $freeBytes = disk_free_space($dir);
                $lastDiskCheck = $now;

                if ($freeBytes === false || $freeBytes <= $reserveBytes) {
                    $diskSpaceAbort = true;
                    return 1; // Abort cURL; the partial file is removed below.
                }
            }

            // Log progress every 30 seconds
            if ($downloadSize > 0 && ($now - $lastLog) >= 30) {
                $percent = round(($downloaded / $downloadSize) * 100, 1);
                $downloadedMB = round($downloaded / 1024 / 1024, 2);
                $totalMB = round($downloadSize / 1024 / 1024, 2);
                Log::info("Download progress: {$percent}% ({$downloadedMB}MB / {$totalMB}MB)");
                $lastLog = $now;
            }
            return 0;
        });
        
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        fclose($fp);
        if ($errorHandle !== false) {
            fclose($errorHandle);
        }
        
        // Read verbose output if there was an error
        $verboseOutput = '';
        if ($errorFile !== false && file_exists($errorFile)) {
            // cURL diagnostics are small, but cap the read defensively.
            $verboseHandle = fopen($errorFile, 'rb');
            if ($verboseHandle !== false) {
                $verboseOutput = fread($verboseHandle, 8192) ?: '';
                fclose($verboseHandle);
            }
            @unlink($errorFile);
        }
        
        if (!$success || $httpCode !== 200) {
            Log::error("Dropbox download failed", [
                'http_code' => $httpCode,
                'curl_error' => $error,
                'content_type' => $contentType,
                'path' => $dropboxPath,
                'partial_file_size_bytes' => file_exists($localPath) ? filesize($localPath) : 0,
                'aborted_for_disk_space' => $diskSpaceAbort,
                'verbose_output' => substr($verboseOutput, -500),
            ]);
            
            @unlink($localPath); // Clean up failed download
            return false;
        }
        
        $fileSize = filesize($localPath);
        
        if ($fileSize === 0) {
            Log::error("Downloaded file is empty", ['path' => $localPath]);
            @unlink($localPath);
            return false;
        }
        
        Log::info("Download complete", [
            'size_bytes' => $fileSize,
            'size_mb' => round($fileSize / 1024 / 1024, 2),
            'path' => $dropboxPath
        ]);
        
        return true;
    }

    /**
     * Return a Dropbox file's size without downloading its content.
     */
    public function getFileSize(string $filePath): ?int
    {
        try {
            $this->detectTeamAccount();

            $response = Http::withToken($this->getAccessToken())
                ->withOptions(['verify' => false, 'timeout' => 30])
                ->withHeaders($this->getApiHeaders())
                ->post('https://api.dropboxapi.com/2/files/get_metadata', [
                    'path' => $filePath,
                    'include_media_info' => false,
                    'include_deleted' => false,
                ]);

            if (!$response->successful()) {
                Log::warning('Could not determine Dropbox file size before download', [
                    'path' => $filePath,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $size = $response->json('size');

            return is_numeric($size) ? (int) $size : null;
        } catch (\Throwable $e) {
            Log::warning('Dropbox file size preflight failed', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if a file exists in Dropbox
     * 
     * @param string $filePath Path to file in Dropbox
     * @return bool True if file exists and is a file (not a folder)
     */
    public function fileExists(string $filePath): bool
    {
        try {
            $token = $this->getAccessToken();
            $headers = $this->getApiHeaders();
            
            Log::info("Checking if Dropbox file exists", [
                'path' => $filePath,
                'is_team' => $this->isTeamAccount ?? false,
                'member_id' => $this->teamMemberId ?? 'N/A',
                'namespace_id' => $this->rootNamespaceId ?? 'N/A'
            ]);
            
            $response = Http::withToken($token)
                ->withOptions(['verify' => false])
                ->withHeaders($headers)
                ->post('https://api.dropboxapi.com/2/files/get_metadata', [
                    'path' => $filePath,
                    'include_media_info' => false,
                    'include_deleted' => false
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $isFile = ($data['.tag'] ?? '') === 'file';
                
                Log::info('Dropbox file verification successful', [
                    'path' => $filePath,
                    'exists' => true,
                    'is_file' => $isFile,
                    'size' => $data['size'] ?? null
                ]);
                
                return $isFile;
            }

            $errorData = $response->json();
            Log::warning('Dropbox file not found or inaccessible', [
                'path' => $filePath,
                'status' => $response->status(),
                'error' => $errorData['error_summary'] ?? $errorData['error']['.tag'] ?? 'Unknown error',
                'full_error' => $errorData
            ]);
            
            return false;

        } catch (\Exception $e) {
            Log::error('Exception while checking Dropbox file existence', [
                'path' => $filePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function convertAndUploadMtsToMp4($filePath)
    {
        // Strip extension case-insensitively (handles both .mts and .MTS)
        $baseName = preg_replace('/\.mts$/i', '', basename($filePath));

        // Local temp paths
        $localMtsPath       = storage_path('app/tmp/' . $baseName . '.MTS');
        $localConvertedPath = storage_path('app/tmp/' . $baseName . '.mp4');

        // Ensure temp directory exists
        if (!is_dir(storage_path('app/tmp'))) {
            mkdir(storage_path('app/tmp'), 0755, true);
        }

        // Step 1: Download the MTS file from Dropbox to a local temp file
        Log::info('Downloading MTS file from Dropbox', ['dropbox_path' => $filePath, 'local_path' => $localMtsPath]);
        $downloaded = $this->downloadToFile($filePath, $localMtsPath);

        if (!$downloaded || !file_exists($localMtsPath)) {
            Log::error('Failed to download MTS file from Dropbox', ['dropbox_path' => $filePath]);
            return false;
        }

        // Step 2: Convert with FFmpeg
        $command = "ffmpeg -i " . escapeshellarg($localMtsPath)
            . " -vcodec libx264 -crf 28 -preset medium -acodec aac -b:a 128k -movflags +faststart "
            . escapeshellarg($localConvertedPath)
            . " 2>&1";

        $output = [];
        $resultCode = null;
        exec($command, $output, $resultCode);

        // Clean up the downloaded MTS regardless of outcome
        @unlink($localMtsPath);

        if ($resultCode !== 0) {
            Log::error('FFmpeg conversion failed', ['command' => $command, 'output' => $output]);
            @unlink($localConvertedPath);
            return false;
        }

        Log::info('FFmpeg conversion successful', ['output' => $output]);

        // Step 3: Upload the converted MP4 to Dropbox — same folder as the original MTS
        $dropboxPath = rtrim(dirname($filePath), '/') . '/' . $baseName . '.mp4';
        $uploadResult = $this->uploadFileToDropbox($localConvertedPath, $dropboxPath);

        // Clean up local converted file
        @unlink($localConvertedPath);

        if ($uploadResult) {
            $this->deleteFileFromDropbox($filePath);
            Log::info('Converted video uploaded successfully to Dropbox', ['dropbox_path' => $dropboxPath]);
            return true;
        }

        Log::error('Failed to upload converted video to Dropbox', ['dropbox_path' => $dropboxPath]);
        return false;
    }

    private function uploadFileToDropbox($localPath, $dropboxPath)
    {
        $token = $this->getAccessToken();

        // Build Dropbox-API-Arg header (metadata goes here, NOT in the body)
        $apiArg = json_encode([
            'path'       => $dropboxPath,
            'mode'       => 'add',
            'autorename' => true,
            'mute'       => false,
        ]);

        // Build cURL headers
        $curlHeaders = [
            'Authorization: Bearer ' . $token,
            'Dropbox-API-Arg: ' . $apiArg,
            'Content-Type: application/octet-stream',
        ];

        // Add team account headers if needed
        if ($this->isTeamAccount && $this->teamMemberId) {
            $curlHeaders[] = 'Dropbox-API-Select-User: ' . $this->teamMemberId;
            if ($this->rootNamespaceId) {
                $curlHeaders[] = 'Dropbox-API-Path-Root: ' . json_encode([
                    '.tag'         => 'namespace_id',
                    'namespace_id' => $this->rootNamespaceId,
                ]);
            }
        }

        try {
            $fileSize = filesize($localPath);
            $fp = fopen($localPath, 'rb');
            if (!$fp) {
                Log::error('Cannot open local file for upload', ['path' => $localPath]);
                return false;
            }

            $ch = curl_init('https://content.dropboxapi.com/2/files/upload');
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_INFILE, $fp);
            curl_setopt($ch, CURLOPT_INFILESIZE, $fileSize);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3600);

            $responseBody = curl_exec($ch);
            $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError    = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if ($curlError) {
                Log::error('cURL error during Dropbox upload', ['error' => $curlError]);
                return false;
            }

            if ($httpCode === 200) {
                Log::info('File uploaded to Dropbox successfully', ['dropbox_path' => $dropboxPath]);
                return true;
            }

            Log::error('Failed to upload file to Dropbox', ['status' => $httpCode, 'body' => $responseBody]);
            return false;

        } catch (\Exception $e) {
            Log::error('Exception during file upload', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function deleteFileFromDropbox($filePath)
    {
    // Delete the original MTS file from Dropbox after upload
    $token = $this->getAccessToken();
    $headers = $this->getApiHeaders();
    
    try {
        $response = Http::withToken($token)
            ->withOptions(['verify' => false])
            ->withHeaders($headers)
            ->post('https://api.dropboxapi.com/2/files/delete_v2', [
                'path' => $filePath
            ]);
        
        if ($response->successful()) {
            Log::info('Original file deleted from Dropbox', ['filePath' => $filePath]);
            return true;
        } else {
            Log::error('Failed to delete file from Dropbox', ['status' => $response->status(), 'body' => $response->body()]);
            return false;
        }
    } catch (\Exception $e) {
        Log::error('Exception during file deletion', ['error' => $e->getMessage()]);
        return false;
    }
    }

}
