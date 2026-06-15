<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    public function index()
    {
        $currentUser = auth()->user();
        $availableRoles = [];

        if ($currentUser->hasRole('superAdmin')) {
            $availableRoles = ['admin', 'manager', 'user'];
        } elseif ($currentUser->hasRole('admin')) {
            $availableRoles = ['manager', 'user'];
        } elseif ($currentUser->hasRole('manager')) {
            $availableRoles = ['user'];
        }

        return Inertia::render('AdminSide/UserManagement', [
            'availableRoles' => $availableRoles,
        ]);
    }

    public function store(Request $request)
    {
        Log::info('Store Request Data: ', $request->all());

        $currentUser = auth()->user();
        $requestedRole = $request->userType;

        // Enforce role-based creation restrictions
        if ($currentUser->hasRole('superAdmin')) {
            // Super Admin can create any role
        } elseif ($currentUser->hasRole('admin')) {
            // Admin can only create managers and users
            if (!in_array($requestedRole, ['manager', 'user'])) {
                return response()->json(['message' => 'You are not authorized to create this user type.'], 403);
            }
        } elseif ($currentUser->hasRole('manager')) {
            // Manager can only create users
            if ($requestedRole !== 'user') {
                return response()->json(['message' => 'You are not authorized to create this user type.'], 403);
            }
        } else {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'fullName' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|min:6|confirmed',
            'userType' => 'required|string',
            'profile_picture' => 'nullable|image|max:2048'
        ]);

        $profilePath = null;
        if ($request->hasFile('profile_picture')) {
            $profilePath = $request->file('profile_picture')->store('profiles', 'public');
        }

        $user = User::create([
            'name' => $request->fullName,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'profile_picture' => $profilePath,
            'status' => true,
            'can_edit_profile' => true,
            'created_by' => $currentUser->id,
        ]);

        // Use syncRoles to ensure only ONE role is assigned
        $user->syncRoles([$request->userType]);

        // Ensure privileged users (admin/manager) always have status and can_edit_profile enabled
        if (in_array($request->userType, ['admin', 'manager', 'superAdmin'])) {
            $user->update([
                'status' => true,
                'can_edit_profile' => true,
            ]);
        }

        return response()->json([
            'message' => 'User created successfully',
            'user' => $user->load('roles')
        ]);
    }

    public function users_list()
    { 
        $currentUser = auth()->user();
        $query = User::with('roles');

        if ($currentUser->hasRole('superAdmin')) {
            // SuperAdmin sees ALL users (including other superAdmins, admins, managers, users)
            // No filter needed — return everyone
        } elseif ($currentUser->hasRole('admin')) {
            // Admin sees only users they created (managers and regular users)
            $query->where('created_by', $currentUser->id);
        } elseif ($currentUser->hasRole('manager')) {
            // Manager sees only regular users they created
            $query->where('created_by', $currentUser->id)
                ->whereHas('roles', function ($r) {
                    $r->where('name', 'user');
                });
        } else {
            // Regular users should not access this at all
            abort(403);
        }

        $users = $query->latest()->get()->map(function ($u) {
            return [
                'id' => $u->id,
                'userName' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone ?? '-',
                'userType' => $u->getRoleNames()->first() ?? 'N/A',
                'status' => $u->status ?? true,
                'can_edit_profile' => $u->can_edit_profile ?? true,
                'profile_picture' => $u->profile_picture 
                    ? asset('storage/' . $u->profile_picture) 
                    : null,
            ];
        });

        return response()->json($users);
    }

    public function update(Request $request, $id)
    {
        Log::info('Update Request Data: ', $request->all());

        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Enforce role-based update restrictions
        $currentUser = auth()->user();
        $targetRole = $user->getRoleNames()->first();

        if ($currentUser->hasRole('superAdmin')) {
            // Super Admin can update anyone
        } elseif ($currentUser->hasRole('admin')) {
            // Admin can only update managers and users — NOT superAdmins or other admins
            if (in_array($targetRole, ['superAdmin', 'admin'])) {
                return response()->json(['message' => 'You are not authorized to modify this user.'], 403);
            }
        } elseif ($currentUser->hasRole('manager')) {
            // Manager can only update users — NOT admins, superAdmins, or other managers
            if ($targetRole !== 'user') {
                return response()->json(['message' => 'You are not authorized to modify this user.'], 403);
            }
        } else {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $rules = [
            'fullName' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,' . $id,
            'phone' => 'nullable|string|max:20',
            'userType' => 'sometimes|required|string',
            'status' => 'sometimes|boolean',
            'can_edit_profile' => 'sometimes|boolean',
            'profile_picture' => 'nullable|image|max:2048'
        ];

        if ($request->filled('password')) {
            $rules['password'] = 'required|min:6|confirmed';
        }

        $request->validate($rules);

        $updateData = [];
        
        if ($request->has('fullName')) {
            $updateData['name'] = $request->fullName;
        }
        
        if ($request->has('email')) {
            $updateData['email'] = $request->email;
        }
        
        if ($request->has('phone')) {
            $updateData['phone'] = $request->phone;
        }
        
        // Check if user is admin/superAdmin/manager - these roles always have status and can_edit_profile enabled
        $isPrivileged = $user->hasRole('admin') || $user->hasRole('superAdmin') || $user->hasRole('manager');

        if ($request->has('status')) {
            // Prevent changing status for privileged users
            if (!$isPrivileged) {
                $updateData['status'] = $request->status ? 1 : 0;

                // If status is being turned off, log out the user from all sessions
                if (!$request->status) {
                    // Delete all sessions for this user
                    DB::table('sessions')->where('user_id', $user->id)->delete();

                    Log::info("User {$user->name} (ID: {$user->id}) has been logged out due to status being disabled by admin.");
                }
            } else {
                // Always keep privileged role status enabled
                $updateData['status'] = 1;
            }
        }

        if ($request->has('can_edit_profile')) {
            // Prevent changing can_edit_profile for privileged users
            if (!$isPrivileged) {
                $updateData['can_edit_profile'] = $request->can_edit_profile ? 1 : 0;
            } else {
                // Always keep privileged role profile edit enabled
                $updateData['can_edit_profile'] = 1;
            }
        }

        if ($request->filled('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        if ($request->hasFile('profile_picture')) {
            if ($user->profile_picture) {
                Storage::disk('public')->delete($user->profile_picture);
            }
            
            $profilePath = $request->file('profile_picture')->store('profiles', 'public');
            $updateData['profile_picture'] = $profilePath;
        }

        $user->update($updateData);

        if ($request->has('userType')) {
            $user->syncRoles([$request->userType]);
        }

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user->load('roles')
        ]);
    }

    public function destroy($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Enforce role-based delete restrictions
        $currentUser = auth()->user();
        $targetRole = $user->getRoleNames()->first();

        if ($currentUser->hasRole('superAdmin')) {
            // Super Admin can delete anyone
        } elseif ($currentUser->hasRole('admin')) {
            // Admin can only delete managers and users
            if (in_array($targetRole, ['superAdmin', 'admin'])) {
                return response()->json(['message' => 'You are not authorized to delete this user.'], 403);
            }
        } elseif ($currentUser->hasRole('manager')) {
            // Manager can only delete users
            if ($targetRole !== 'user') {
                return response()->json(['message' => 'You are not authorized to delete this user.'], 403);
            }
        } else {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->profile_picture) {
            Storage::disk('public')->delete($user->profile_picture);
        }

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully'
        ]);
    }
}
