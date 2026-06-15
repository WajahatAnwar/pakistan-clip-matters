<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the admin's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Display the user's profile form (for regular users).
     */
    public function userEdit(Request $request): Response
    {
        $user = $request->user();
        
        // Check if user has permission to edit profile
        if (!$user->can_edit_profile && !$user->hasRole('admin')) {
            abort(403, 'You do not have permission to edit your profile. Please contact the administrator.');
        }
        
        return Inertia::render('UserSide/Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        
        // Check if user has permission to edit profile (skip for admins)
        if (!$user->hasRole('admin') && !$user->can_edit_profile) {
            return Redirect::back()->with('error', 'You do not have permission to edit your profile.');
        }
        
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        // Redirect based on user role
        if ($user->hasRole('admin')) {
            return Redirect::route('profile.edit');
        }
        
        return Redirect::route('user.profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
