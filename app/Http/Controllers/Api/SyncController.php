<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SyncController extends Controller
{
    /**
     * Pull data from server.
     */
    public function pull(Request $request)
    {
        $lastSyncedAt = $request->query('last_synced_at');
        
        $userQuery = User::query();
        $roleQuery = Role::query();

        if ($lastSyncedAt) {
            $timestamp = Carbon::parse($lastSyncedAt);
            $userQuery->where('updated_at', '>', $timestamp);
            $roleQuery->where('updated_at', '>', $timestamp);
        }

        return response()->json([
            'users' => $userQuery->get(),
            'roles' => $roleQuery->get(),
            'server_time' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Push data to server (Insert Only strategy).
     */
    public function push(Request $request)
    {
        $validated = $request->validate([
            'users' => 'array',
            'users.*.email' => 'required|email',
            'users.*.first_name' => 'required|string',
            'users.*.last_name' => 'required|string',
            'users.*.password' => 'required|string',
            'roles' => 'array',
            'roles.*.name' => 'required|string',
        ]);

        $insertedUsersCount = 0;
        $insertedRolesCount = 0;

        if (!empty($validated['users'])) {
            foreach ($validated['users'] as $userData) {
                // Insert only if email doesn't exist
                if (!User::where('email', $userData['email'])->exists()) {
                    User::create([
                        'first_name' => $userData['first_name'],
                        'middle_name' => $userData['middle_name'] ?? null,
                        'last_name' => $userData['last_name'],
                        'email' => $userData['email'],
                        'password' => bcrypt($userData['password']),
                        'is_active' => $userData['is_active'] ?? true,
                    ]);
                    $insertedUsersCount++;
                }
            }
        }

        if (!empty($validated['roles'])) {
            foreach ($validated['roles'] as $roleData) {
                // Insert only if name doesn't exist
                if (!Role::where('name', $roleData['name'])->exists()) {
                    Role::create([
                        'name' => $roleData['name'],
                        'guard_name' => 'web', // Default guard
                    ]);
                    $insertedRolesCount++;
                }
            }
        }

        return response()->json([
            'message' => 'Sync push completed.',
            'inserted_users' => $insertedUsersCount,
            'inserted_roles' => $insertedRolesCount,
        ]);
    }
}
