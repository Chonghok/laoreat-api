<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\Admin;


class AdminAuthController extends Controller
{
    public function login(Request $request) {
        $request->validate([
            'login'    => 'required|string',
            'password' => 'required|string',
            'device_name' => 'nullable|string',
        ]);

        $login = $request->login;

        $admin = Admin::where('email', $login)
            ->orWhere('username', $login)
            ->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        if ((int)$admin->is_active !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'This admin account is disabled'
            ], 403);
        }

        // Optional: clear old tokens so 1 admin = 1 session (prevents “stuck on 1 account” confusion)
        // $admin->tokens()->delete();

        $device = $request->device_name ?: 'admin-dashboard';
        $token = $admin->createToken($device)->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'admin' => [
                'id' => $admin->id,
                'username' => $admin->username,
                'email' => $admin->email,
                'role' => $admin->role,
                'is_active' => (int)$admin->is_active,
                'profile_url' => $admin->profile_url,
            ],
        ]);
    }

    public function me(Request $request) {
        // IMPORTANT: use guard admin
        $admin = $request->user('admin');

        return response()->json([
            'success' => true,
            'admin' => $admin
        ]);
    }

    public function logout(Request $request)
    {
        $admin = $request->user('admin');

        if ($admin && $admin->currentAccessToken()) {
            $admin->currentAccessToken()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out'
        ]);
    }
}
