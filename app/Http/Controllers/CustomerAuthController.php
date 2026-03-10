<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CustomerAuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'username'     => 'required|string|max:100',
            'email'        => 'required|email|max:255|unique:customers,email',
            'password'     => 'required|string|min:4|confirmed',
            'phone_number' => 'required|string|max:30|unique:customers,phone_number',
        ]);

        $customer = Customer::create([
            'username'          => $request->username,
            'email'             => $request->email,
            'password'          => Hash::make($request->password),
            'phone_number'      => $request->phone_number,
            'phone_verified_at' => null,
            'profile_url'       => env('DEFAULT_PROFILE_URL'),
            'profile_public_id' => null,
            'is_active'         => true,
        ]);

        // delete old unused phone verification OTPs for this customer
        CustomerOtp::where('customer_id', $customer->id)
            ->where('type', 'phone_verification')
            ->whereNull('verified_at')
            ->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        CustomerOtp::create([
            'customer_id'  => $customer->id,
            'email'        => $customer->email,
            'phone_number' => $customer->phone_number,
            'code'         => $code,
            'type'         => 'phone_verification',
            'expires_at'   => now()->addMinutes(5),
            'verified_at'  => null,
        ]);

        return response()->json([
            'success'  => true,
            'message'  => 'Customer registered successfully. Please verify your phone number.',
            'customer' => [
                'id'           => $customer->id,
                'username'     => $customer->username,
                'email'        => $customer->email,
                'phone_number' => $customer->phone_number,
            ],
            'demo_otp' => $code,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'       => 'required|email',
            'password'    => 'required|string',
            'device_name' => 'nullable|string',
        ]);

        $customer = Customer::where('email', $request->email)->first();

        if (!$customer || !Hash::check($request->password, $customer->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        if (!$customer->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This customer account is disabled'
            ], 403);
        }

        if (is_null($customer->phone_verified_at)) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify your phone number first.'
            ], 403);
        }

        $device = $request->device_name ?: 'flutter-app';
        $token = $customer->createToken($device)->plainTextToken;

        return response()->json([
            'success'  => true,
            'token'    => $token,
            'customer' => [
                'id'                => $customer->id,
                'username'          => $customer->username,
                'email'             => $customer->email,
                'phone_number'      => $customer->phone_number,
                'phone_verified_at' => $customer->phone_verified_at,
                'profile_url'       => $customer->profile_url,
                'is_active'         => (int) $customer->is_active,
            ],
        ]);
    }

    public function me(Request $request)
    {
        $customer = $request->user();

        return response()->json([
            'success'  => true,
            'customer' => $customer,
        ]);
    }

    public function logout(Request $request)
    {
        $customer = $request->user();

        if ($customer && $customer->currentAccessToken()) {
            $customer->currentAccessToken()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out'
        ]);
    }

    public function sendPhoneOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:customers,email',
        ]);

        $customer = Customer::where('email', $request->email)->first();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found.'
            ], 404);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        CustomerOtp::where('customer_id', $customer->id)
            ->where('type', 'phone_verification')
            ->whereNull('verified_at')
            ->delete();

        CustomerOtp::create([
            'customer_id'  => $customer->id,
            'email'        => $customer->email,
            'phone_number' => $customer->phone_number,
            'code'         => $code,
            'type'         => 'phone_verification',
            'expires_at'   => now()->addMinutes(2),
            'verified_at'  => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Phone OTP sent successfully.',
            'demo_otp' => $code,
        ]);
    }

    public function verifyPhoneOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:customers,email',
            'code'  => 'required|string|size:6',
        ]);

        $customer = Customer::where('email', $request->email)->first();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found.'
            ], 404);
        }

        $otp = CustomerOtp::where('customer_id', $customer->id)
            ->where('type', 'phone_verification')
            ->where('code', $request->code)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (!$otp) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP.'
            ], 422);
        }

        $otp->update([
            'verified_at' => now(),
        ]);

        $customer->update([
            'phone_verified_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Phone number verified successfully.'
        ]);
    }
}