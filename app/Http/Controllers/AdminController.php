<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class AdminController extends Controller
{
    // Insert Admins
    public function store(Request $request) {
        $viewer = $request->user('admin');

        if (!$viewer) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $viewerRole = strtolower($viewer->role);

        if ($viewerRole === 'operator') {
            return response()->json([
                'success' => false,
                'message' => 'Operators cannot create admins.'
            ], 403);
        }

        $request->validate([
            'username' => 'required|string|max:100|unique:admins,username',
            'role' => 'required|string|in:superadmin,manager,operator',
            'email' => 'required|email|unique:admins,email',
            'password' => 'required|min:4',
            'profile' => 'nullable|image|max:2048',
        ]);

        $targetRole = strtolower($request->role);

        // Manager can only create operator
        if ($viewerRole === 'manager' && $targetRole !== 'operator') {
            return response()->json([
                'success' => false,
                'message' => 'Managers can only create Operator accounts.'
            ], 403);
        }

        // Upload image (same as before)
        $profileUrl = null;
        $profilePublicId = null;

        if ($request->hasFile('profile')) {
            $upload = Cloudinary::uploadApi()->upload(
                $request->file('profile')->getRealPath(),
                ['folder' => 'laoreat/admins']
            );

            $profileUrl = $upload['secure_url'] ?? null;
            $profilePublicId = $upload['public_id'] ?? null;
        }

        DB::table('admins')->insert([
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'profile_url' => $profileUrl,
            'profile_public_id' => $profilePublicId,
            'role' => $request->role,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Admin created successfully'
        ], 201);
    }

    // Get all Admins
    public function index() {
        $admins = DB::table('admins')
            ->select('id', 'username', 'email', 'profile_url', 'role', 'is_active', 'created_at', 'updated_at')
            ->orderBy('id', 'asc')
            ->get();

        $default = env('DEFAULT_PROFILE_URL');
        $admins = $admins->map(function ($a) use ($default) {
            $a->profile_url = $a->profile_url ?: ($default ?: null);
            return $a;
        });
        
        return response()->json([
            'success' => true, 
            'data' => $admins
        ]);
    }

    // Update Admins
    public function update(Request $request, $id) {
        $admin = DB::table('admins')->where('id', $id)->first();

        if (!$admin) {
            return response()->json([
                'success' => false,
                'message' => 'Admin not found'
            ], 404);
        }

        $viewer = $request->user('admin');
        $viewerRole = strtolower($viewer->role);
        $targetRole = strtolower($admin->role);

        $isSelf = (int)$viewer->id === (int)$admin->id;

        // ❌ Operator cannot update anyone
        if ($viewerRole === 'operator') {
            return response()->json([
                'success' => false,
                'message' => 'Operators cannot update admins.'
            ], 403);
        }

        // ❌ Manager restrictions
        if ($viewerRole === 'manager') {

            // Manager updating themselves → allowed
            if ($isSelf) {
                // allow
            }

            // Manager updating operator → allowed
            elseif ($targetRole === 'operator') {
                // allow
            }

            // Anything else → blocked
            else {
                return response()->json([
                    'success' => false,
                    'message' => 'Managers can only update Operator accounts.'
                ], 403);
            }
        }

        // Validate (unique except current id)
        $request->validate([
            'username' => 'required|string|max:100|unique:admins,username,' . $id,
            'role'     => 'required|string|in:superadmin,manager,operator',
            'email'    => 'required|email|unique:admins,email,' . $id,
            'password' => 'nullable|min:4',
            'profile'  => 'nullable|image|max:2048',
            'remove_photo' => 'nullable|in:1',
        ]);

        $updateData = [
            'username'   => $request->username,
            'email'      => $request->email,
            'role'       => $request->role,
            'updated_at' => now(),
        ];

        // Only update password if provided
        if ($request->filled('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        $hasCustomImage = !empty($admin->profile_public_id);

        // 1) Remove profile photo
        if ($request->input('remove_photo') === "1") {

            if ($hasCustomImage) {
                // delete from Cloudinary (only if it's a custom uploaded image)
                Cloudinary::uploadApi()->destroy($admin->profile_public_id);
            }

            // set null so index() will fallback to DEFAULT_PROFILE_URL
            $updateData['profile_url'] = null;
            $updateData['profile_public_id'] = null;
        }

        // 2) Upload / replace new profile photo
        if ($request->hasFile('profile')) {

            // delete old custom image first
            if ($hasCustomImage) {
                Cloudinary::uploadApi()->destroy($admin->profile_public_id);
            }

            $upload = Cloudinary::uploadApi()->upload(
                $request->file('profile')->getRealPath(),
                ['folder' => 'laoreat/admins']
            );

            $updateData['profile_url'] = $upload['secure_url'] ?? null;
            $updateData['profile_public_id'] = $upload['public_id'] ?? null;
        }

        DB::table('admins')->where('id', $id)->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Admin updated successfully'
        ]);
    }

    // Enable / Disable Admin
    public function setStatus(Request $request, $id) {
        $admin = DB::table('admins')->where('id', $id)->first();

        if (!$admin) {
            return response()->json([
                'success' => false,
                'message' => 'Admin not found'
            ], 404);
        }

        $request->validate([
            'is_active' => 'required|in:0,1'
        ]);

        $viewer = $request->user('admin');
        $viewerRole = strtolower($viewer->role);
        $targetRole = strtolower($admin->role);
        $isSelf = (int)$viewer->id === (int)$id;

        // ❌ Prevent disabling yourself (for everyone)
        if ($isSelf && (int)$request->is_active === 0) {
            return response()->json([
                'success' => false,
                'message' => "You can't disable your own account."
            ], 403);
        }

        // ❌ Operator cannot manage anyone
        if ($viewerRole === 'operator') {
            return response()->json([
                'success' => false,
                'message' => 'Operators cannot manage admins.'
            ], 403);
        }

        // ❌ Manager restrictions
        if ($viewerRole === 'manager') {

            // Manager can manage operator
            if ($targetRole === 'operator') {
                // allowed
            }

            // Manager managing themselves → only if enabling (self-disable already blocked above)
            elseif ($isSelf) {
                // allowed (enable self only)
            }

            // Otherwise block
            else {
                return response()->json([
                    'success' => false,
                    'message' => 'Managers can only manage Operator accounts.'
                ], 403);
            }
        }

        DB::table('admins')->where('id', $id)->update([
            'is_active' => (int)$request->is_active,
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => ((int)$request->is_active === 1) ? 'Admin enabled' : 'Admin disabled'
        ]);
    }
}
