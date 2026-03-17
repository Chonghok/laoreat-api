<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class CustomerAuthController extends Controller
{
    private function formatKhPhoneNumber(string $phone): string
    {
        // remove everything except digits and +
        $phone = preg_replace('/[^\d+]/', '', trim($phone));

        // remove +855 or 855 if already included
        if (str_starts_with($phone, '+855')) {
            $phone = substr($phone, 4);
        } elseif (str_starts_with($phone, '855')) {
            $phone = substr($phone, 3);
        }

        // remove leading 0
        if (str_starts_with($phone, '0')) {
            $phone = substr($phone, 1);
        }

        // apply Cambodia spacing
        $formattedLocal = $this->formatKhLocalNumber($phone);

        return '+855 ' . $formattedLocal;
    }

    private function formatKhLocalNumber(string $digits): string
    {
        $len = strlen($digits);

        // 8 digits => 12 345 678
        if ($len === 8) {
            return substr($digits, 0, 2) . ' ' .
                   substr($digits, 2, 3) . ' ' .
                   substr($digits, 5, 3);
        }

        // 9 digits => 97 234 5678
        if ($len === 9) {
            return substr($digits, 0, 2) . ' ' .
                   substr($digits, 2, 3) . ' ' .
                   substr($digits, 5, 4);
        }

        // fallback if unexpected length
        return $digits;
    }

    public function register(Request $request)
    {
        $request->validate([
            'username'     => 'required|string|max:100',
            'email'        => 'required|email|max:255|unique:customers,email',
            'password'     => 'required|string|min:4|confirmed',
            'phone_number' => 'required|string|max:30',
        ]);

        $formattedPhone = $this->formatKhPhoneNumber($request->phone_number);

        if (Customer::where('phone_number', $formattedPhone)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This phone number is already registered.'
            ], 422);
        }

        $customer = Customer::create([
            'username'          => $request->username,
            'email'             => $request->email,
            'password'          => Hash::make($request->password),
            'phone_number'      => $formattedPhone,
            'phone_verified_at' => null,
            'profile_url'       => null,
            'profile_public_id' => null,
            'is_active'         => true,
        ]);

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
                'profile_url'  => $customer->profile_url ?: env('DEFAULT_PROFILE_URL'),
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
                'message' => 'Please verify your phone number first.',
                'customer' => [
                    'email' => $customer->email,
                    'phone_number' => $customer->phone_number,
                ]
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
                'profile_url'       => $customer->profile_url ?: env('DEFAULT_PROFILE_URL'),
                'is_active'         => (int) $customer->is_active,
            ],
        ]);
    }

    public function me(Request $request)
    {
        $customer = $request->user();

        return response()->json([
            'success'  => true,
            'customer' => [
                'id'                => $customer->id,
                'username'          => $customer->username,
                'email'             => $customer->email,
                'phone_number'      => $customer->phone_number,
                'phone_verified_at' => $customer->phone_verified_at,
                'profile_url'       => $customer->profile_url ?: env('DEFAULT_PROFILE_URL'),
                'profile_public_id' => $customer->profile_public_id,
                'is_active'         => (int) $customer->is_active,
                'created_at'        => $customer->created_at,
                'updated_at'        => $customer->updated_at,
            ],
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
            'expires_at'   => now()->addMinutes(5),
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

    public function checkRegisterAvailability(Request $request)
    {
        $request->validate([
            'email'        => 'required|email|max:255',
            'phone_number' => 'nullable|string|max:30',
        ]);

        $emailExists = Customer::where('email', $request->email)->exists();

        $phoneExists = false;

        if ($request->filled('phone_number')) {
            $formattedPhone = $this->formatKhPhoneNumber($request->phone_number);
            $phoneExists = Customer::where('phone_number', $formattedPhone)->exists();
        }

        return response()->json([
            'success' => true,
            'available' => !$emailExists && !$phoneExists,
            'errors' => [
                'email' => $emailExists ? 'This email is already registered.' : null,
                'phone_number' => $phoneExists ? 'This phone number is already registered.' : null,
            ]
        ]);
    }

    public function sendResetPasswordOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:customers,email',
        ]);

        $customer = Customer::where('email', $request->email)->first();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Email not found.'
            ], 404);
        }

        CustomerOtp::where('customer_id', $customer->id)
            ->where('type', 'password_reset')
            ->whereNull('verified_at')
            ->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        CustomerOtp::create([
            'customer_id'  => $customer->id,
            'email'        => $customer->email,
            'phone_number' => $customer->phone_number,
            'code'         => $code,
            'type'         => 'password_reset',
            'expires_at'   => now()->addMinutes(5),
            'verified_at'  => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password reset OTP sent successfully.',
            'demo_otp' => $code,
        ]);
    }

    public function verifyResetPasswordOtp(Request $request)
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
            ->where('type', 'password_reset')
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

        return response()->json([
            'success' => true,
            'message' => 'OTP verified successfully.'
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:customers,email',
            'password' => 'required|string|min:4|confirmed',
        ]);

        $customer = Customer::where('email', $request->email)->first();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found.'
            ], 404);
        }

        $verifiedOtp = CustomerOtp::where('customer_id', $customer->id)
            ->where('type', 'password_reset')
            ->whereNotNull('verified_at')
            ->latest()
            ->first();

        if (!$verifiedOtp) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify OTP first.'
            ], 403);
        }

        $customer->update([
            'password' => Hash::make($request->password),
        ]);

        CustomerOtp::where('customer_id', $customer->id)
            ->where('type', 'password_reset')
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successful.'
        ]);
    }

    public function updateProfile(Request $request)
    {
        $customer = $request->user();

        $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('customers', 'email')->ignore($customer->id),
            ],
        ]);

        $customer->update([
            'username' => $request->username,
            'email' => $request->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'customer' => [
                'id' => $customer->id,
                'username' => $customer->fresh()->username,
                'email' => $customer->fresh()->email,
                'phone_number' => $customer->fresh()->phone_number,
                'phone_verified_at' => $customer->fresh()->phone_verified_at,
                'profile_url' => $customer->fresh()->profile_url ?: env('DEFAULT_PROFILE_URL'),
            ],
        ]);
    }

    public function sendProfilePhoneChangeOtp(Request $request)
    {
        $customer = $request->user();

        $request->validate([
            'phone_number' => ['required', 'string', 'max:30'],
        ]);

        $formattedPhone = $this->formatKhPhoneNumber($request->phone_number);

        if ($formattedPhone !== $customer->phone_number) {
            $exists = Customer::where('phone_number', $formattedPhone)
                ->where('id', '!=', $customer->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'This phone number is already registered.'
                ], 422);
            }
        }

        CustomerOtp::where('customer_id', $customer->id)
            ->where('type', 'phone_change')
            ->whereNull('verified_at')
            ->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        CustomerOtp::create([
            'customer_id'  => $customer->id,
            'email'        => $customer->email,
            'phone_number' => $formattedPhone,
            'code'         => $code,
            'type'         => 'phone_change',
            'expires_at'   => now()->addMinutes(5),
            'verified_at'  => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Phone change OTP sent successfully.',
            'demo_otp' => $code,
        ]);
    }

    public function verifyProfilePhoneChangeOtp(Request $request)
    {
        $customer = $request->user();

        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $otp = CustomerOtp::where('customer_id', $customer->id)
            ->where('type', 'phone_change')
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

        $phoneAlreadyUsed = Customer::where('phone_number', $otp->phone_number)
            ->where('id', '!=', $customer->id)
            ->exists();

        if ($phoneAlreadyUsed) {
            return response()->json([
                'success' => false,
                'message' => 'This phone number is already registered.'
            ], 422);
        }

        $otp->update([
            'verified_at' => now(),
        ]);

        $customer->update([
            'phone_number' => $otp->phone_number,
            'phone_verified_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Phone number updated successfully.',
            'customer' => [
                'id' => $customer->id,
                'phone_number' => $customer->fresh()->phone_number,
                'phone_verified_at' => $customer->fresh()->phone_verified_at,
            ],
        ]);
    }

    public function changePassword(Request $request)
    {
        $customer = $request->user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:4', 'confirmed'],
        ]);

        if (!Hash::check($request->current_password, $customer->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.'
            ], 422);
        }

        $customer->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.'
        ]);
    }

    public function updateProfilePhoto(Request $request)
{
    $customer = $request->user();

    $request->validate([
        'profile' => ['required', 'image', 'max:2048'],
    ]);

    // delete old custom image first
    if (!empty($customer->profile_public_id)) {
        Cloudinary::uploadApi()->destroy($customer->profile_public_id);
    }

    $upload = Cloudinary::uploadApi()->upload(
        $request->file('profile')->getRealPath(),
        ['folder' => 'laoreat/customers']
    );

    $customer->update([
        'profile_url' => $upload['secure_url'] ?? null,
        'profile_public_id' => $upload['public_id'] ?? null,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Profile photo updated successfully.',
        'customer' => [
            'id' => $customer->id,
            'profile_url' => $customer->fresh()->profile_url ?: env('DEFAULT_PROFILE_URL'),
            'profile_public_id' => $customer->fresh()->profile_public_id,
        ],
    ]);
}

public function removeProfilePhoto(Request $request)
{
    $customer = $request->user();

    if (!empty($customer->profile_public_id)) {
        Cloudinary::uploadApi()->destroy($customer->profile_public_id);
    }

    $customer->update([
        'profile_url' => null,
        'profile_public_id' => null,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Profile photo removed successfully.',
        'customer' => [
            'id' => $customer->id,
            'profile_url' => $customer->fresh()->profile_url ?: env('DEFAULT_PROFILE_URL'),
            'profile_public_id' => null,
        ],
    ]);
}
}