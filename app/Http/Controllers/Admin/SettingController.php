<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class SettingController extends Controller
{
    public function index()
    {
        return Inertia::render('AdminSide/Settings'); 
    }

    /**
     * Check if a token is valid (exists and not expired)
     *
     * @param string|null $token
     * @param \DateTime|string|null $expiresAt
     * @return bool
     */
    protected function tokenIsValid($token, $expiresAt): bool
    {
        if (empty($token)) {
            return false;
        }

        if (empty($expiresAt)) {
            return true; // Token exists but no expiry set, consider it valid
        }

        return now()->lt($expiresAt);
    }

    public function getSettings(Request $request)
    {
        $user = Auth::user();

        // Use effective credential owners based on role hierarchy
        $googleOwner = $user->getGoogleCredentialOwner();
        $dropboxOwner = $user->getDropboxCredentialOwner();

        $googleConnected = $googleOwner ? $this->tokenIsValid(
            $googleOwner->google_access_token,
            $googleOwner->google_token_expires_at
        ) : false;

        $dropboxConnected = $dropboxOwner ? $this->tokenIsValid(
            $dropboxOwner->dropbox_access_token,
            $dropboxOwner->dropbox_token_expires_at
        ) : false;

        $services = [
            'dropbox' => $dropboxConnected,

            'google' => $googleConnected,
            'youtube' => $googleConnected,
            'google-cloud' => $googleConnected,

            'mail-gun' => !empty(config('services.mailgun.secret')),
            'speaker-identification' => !empty(config('services.assemblyai.key')),
            'transcript-mode' => !empty(config('services.assemblyai.key')),
            'qdrant-cloud' => !empty(config('services.qdrant.key')),
            'cloud-ways' => !empty(config('services.cloudways.key')),
        ];

        return response()->json([
            'services' => $services,
            'email_notifications' => (bool) $user->email_notifications
        ]);
    }

    /**
     * Update email notification preference
     */
    public function updateEmailNotifications(Request $request)
    {
        $request->validate([
            'enabled' => 'required|boolean'
        ]);

        $user = Auth::user();
        $user->email_notifications = $request->enabled;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Email notification preference updated successfully',
            'email_notifications' => (bool) $user->email_notifications
        ]);
    }


}
