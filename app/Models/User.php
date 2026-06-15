<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'profile_picture',
        'password',
        'status',
        'can_edit_profile',
        'email_notifications',
        'created_by',
        'dropbox_access_token',
        'dropbox_refresh_token',
        'dropbox_token_expires_at',
        'dropbox_team_id',
        'dropbox_team_member_id',
        'dropbox_root_namespace_id',
        'dropbox_cursor',
        'google_access_token',
        'google_refresh_token',
        'google_token_expires_at',
        'assemblyai_api_key',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'google_token_expires_at' => 'datetime',
            'dropbox_token_expires_at' => 'datetime',
        ];
    }

    /**
     * Get the videos for the user
     */
    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    /**
     * Get the voice samples for the user
     */
    public function voiceSamples(): HasMany
    {
        return $this->hasMany(VoiceSample::class);
    }

    /**
     * Get the user who created this user
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get users created by this user
     */
    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    /**
     * Get the Super Admin user (for shared Dropbox credentials).
     * Returns the first superAdmin found.
     */
    public static function getSuperAdmin(): ?self
    {
        return static::role('superAdmin')->first();
    }

    /**
     * Get the user whose Dropbox credentials should be used.
     * Dropbox is always shared from the Super Admin.
     */
    public function getDropboxCredentialOwner(): ?self
    {
        if ($this->hasRole('superAdmin')) {
            return $this;
        }

        // All other roles use Super Admin's Dropbox
        return static::getSuperAdmin();
    }

    /**
     * Get the user whose Google/YouTube credentials should be used.
     * - Super Admin: uses own credentials
     * - Admin: uses own credentials
     * - Manager: uses the Admin (creator) credentials
     */
    public function getGoogleCredentialOwner(): ?self
    {
        if ($this->hasRole('superAdmin') || $this->hasRole('admin')) {
            return $this;
        }

        if ($this->hasRole('manager')) {
            // Manager inherits from the Admin who created them
            $creator = $this->creator;
            if ($creator && ($creator->hasRole('admin') || $creator->hasRole('superAdmin'))) {
                return $creator;
            }
            // Fallback: if creator not found, try Super Admin
            return static::getSuperAdmin();
        }

        // Regular users - use their creator's Google credentials
        if ($this->created_by) {
            $creator = $this->creator;
            if ($creator) {
                return $creator->getGoogleCredentialOwner();
            }
        }

        return static::getSuperAdmin();
    }

    /**
     * Refresh Google access token using refresh token
     */
    public function refreshGoogleToken(): bool
    {
        if (!$this->google_refresh_token) {
            return false;
        }

        try {
            $client = new \Google\Client();
            $client->setClientId(env('GOOGLE_CLIENT_ID'));
            $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
            $client->setAccessType('offline');
            
            $newToken = $client->fetchAccessTokenWithRefreshToken($this->google_refresh_token);
            
            if (isset($newToken['error'])) {
                \Log::error('Failed to refresh Google token', ['error' => $newToken['error']]);
                return false;
            }

            $this->google_access_token = $newToken['access_token'];
            
            if (isset($newToken['refresh_token'])) {
                $this->google_refresh_token = $newToken['refresh_token'];
            }
            
            if (isset($newToken['expires_in'])) {
                $this->google_token_expires_at = now()->addSeconds($newToken['expires_in']);
            }
            
            $this->save();
            
            \Log::info('Google token refreshed successfully for user: ' . $this->id);
            return true;
            
        } catch (\Exception $e) {
            \Log::error('Exception refreshing Google token', [
                'user_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Refresh Dropbox access token using refresh token
     */
    public function refreshDropboxToken(): bool
    {
        if (!$this->dropbox_refresh_token) {
            return false;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->dropbox_refresh_token,
                'client_id' => config('services.dropbox.client_id'),
                'client_secret' => config('services.dropbox.client_secret'),
            ]);

            if (!$response->successful()) {
                \Log::error('Failed to refresh Dropbox token', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return false;
            }

            $data = $response->json();
            
            $this->dropbox_access_token = $data['access_token'];
            
            if (isset($data['refresh_token'])) {
                $this->dropbox_refresh_token = $data['refresh_token'];
            }
            
            if (isset($data['expires_in'])) {
                $this->dropbox_token_expires_at = now()->addSeconds($data['expires_in']);
            }
            
            $this->save();
            
            \Log::info('Dropbox token refreshed successfully for user: ' . $this->id);
            return true;
            
        } catch (\Exception $e) {
            \Log::error('Exception refreshing Dropbox token', [
                'user_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if Google/YouTube is connected and token is valid
     * Resolves credentials from the appropriate owner based on role hierarchy.
     */
    public function isGoogleConnected(): bool
    {
        $owner = $this->getGoogleCredentialOwner();
        if (!$owner || !$owner->google_access_token) {
            return false;
        }

        // Check if token has expired
        if ($owner->google_token_expires_at && now()->gte($owner->google_token_expires_at)) {
            if ($owner->google_refresh_token) {
                return $owner->refreshGoogleToken();
            }
            return false;
        }

        return true;
    }

    /**
     * Check if Dropbox is connected and token is valid
     * Resolves credentials from the Super Admin.
     */
    public function isDropboxConnected(): bool
    {
        $owner = $this->getDropboxCredentialOwner();
        if (!$owner || !$owner->dropbox_access_token) {
            return false;
        }

        // Check if token has expired
        if ($owner->dropbox_token_expires_at && now()->gte($owner->dropbox_token_expires_at)) {
            if ($owner->dropbox_refresh_token) {
                return $owner->refreshDropboxToken();
            }
            return false;
        }

        return true;
    }

    /**
     * Get valid Google access token (auto-refreshes if needed)
     * Resolves from the appropriate credential owner.
     */
    public function getGoogleAccessToken(): ?string
    {
        $owner = $this->getGoogleCredentialOwner();
        if (!$owner) return null;

        if (!$owner->isGoogleConnectedDirect()) {
            return null;
        }
        return $owner->google_access_token;
    }

    /**
     * Get valid Dropbox access token (auto-refreshes if needed)
     * Resolves from the Super Admin.
     */
    public function getDropboxAccessToken(): ?string
    {
        $owner = $this->getDropboxCredentialOwner();
        if (!$owner) return null;

        if (!$owner->isDropboxConnectedDirect()) {
            return null;
        }
        return $owner->dropbox_access_token;
    }

    /**
     * Direct check on THIS user's Google connection (no delegation).
     * Used internally to avoid infinite recursion.
     */
    public function isGoogleConnectedDirect(): bool
    {
        if (!$this->google_access_token) {
            return false;
        }

        if ($this->google_token_expires_at && now()->gte($this->google_token_expires_at)) {
            if ($this->google_refresh_token) {
                return $this->refreshGoogleToken();
            }
            return false;
        }

        return true;
    }

    /**
     * Direct check on THIS user's Dropbox connection (no delegation).
     * Used internally to avoid infinite recursion.
     */
    public function isDropboxConnectedDirect(): bool
    {
        if (!$this->dropbox_access_token) {
            return false;
        }

        if ($this->dropbox_token_expires_at && now()->gte($this->dropbox_token_expires_at)) {
            if ($this->dropbox_refresh_token) {
                return $this->refreshDropboxToken();
            }
            return false;
        }

        return true;
    }

    /**
     * Get connection status for all services.
     * Shows effective connection status based on role hierarchy:
     * - Dropbox: always from Super Admin
     * - Google/YouTube: from self (superAdmin/admin) or creator admin (manager)
     */
    public function getConnectionStatus(): array
    {
        $dropboxOwner = $this->getDropboxCredentialOwner();
        $googleOwner = $this->getGoogleCredentialOwner();

        $isSelf = fn($owner) => $owner && $owner->id === $this->id;

        return [
            'google' => [
                'connected' => $this->isGoogleConnected(),
                'connect_url' => url('/connect/youtube'),
                'token_expires_at' => $googleOwner?->google_token_expires_at?->format('Y-m-d H:i:s'),
                'has_refresh_token' => !empty($googleOwner?->google_refresh_token),
                'owned_by' => $isSelf($googleOwner) ? 'self' : ($googleOwner?->name ?? 'N/A'),
                'can_connect' => $this->hasRole('superAdmin') || $this->hasRole('admin'),
            ],
            'dropbox' => [
                'connected' => $this->isDropboxConnected(),
                'connect_url' => url('/connect/dropbox'),
                'token_expires_at' => $dropboxOwner?->dropbox_token_expires_at?->format('Y-m-d H:i:s'),
                'has_refresh_token' => !empty($dropboxOwner?->dropbox_refresh_token),
                'owned_by' => $isSelf($dropboxOwner) ? 'self' : ($dropboxOwner?->name ?? 'N/A'),
                'can_connect' => $this->hasRole('superAdmin'),
            ],
        ];
    }
}
