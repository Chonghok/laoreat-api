<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    private function adminRole(Request $request): string
    {
        return strtolower((string) optional($request->user())->role);
    }

    private function canView(Request $request): bool
    {
        return in_array($this->adminRole($request), ['superadmin', 'manager', 'operator']);
    }

    private function canManage(Request $request): bool
    {
        return in_array($this->adminRole($request), ['superadmin', 'manager']);
    }

    public function index(Request $request)
    {
        if (!$this->canView($request)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission.'
            ], 403);
        }

        $customers = Customer::query()
            ->select([
                'customers.id',
                'customers.username',
                'customers.email',
                'customers.phone_number',
                'customers.phone_verified_at',
                'customers.profile_url',
                'customers.profile_public_id',
                'customers.is_active',
                'customers.created_at',
                'customers.updated_at',
            ])
            ->withCount('orders')
            ->withSum('orders', 'total_amount')
            ->selectSub(function ($q) {
                $q->from('orders')
                    ->selectRaw('MAX(created_at)')
                    ->whereColumn('orders.customer_id', 'customers.id');
            }, 'last_order_at')
            ->orderBy('customers.id', 'asc')
            ->get()
            ->map(function ($customer) {
                return [
                    'id' => $customer->id,
                    'username' => $customer->username,
                    'email' => $customer->email,
                    'phone_number' => $customer->phone_number,
                    'phone_verified_at' => $customer->phone_verified_at,
                    'profile_url' => $customer->profile_url ?: env('DEFAULT_PROFILE_URL'),
                    'profile_public_id' => $customer->profile_public_id,
                    'is_active' => (int) $customer->is_active,
                    'orders_count' => (int) ($customer->orders_count ?? 0),
                    'total_spent' => (float) ($customer->orders_sum_total_amount ?? 0),
                    'last_order_at' => $customer->last_order_at,
                    'created_at' => $customer->created_at,
                    'updated_at' => $customer->updated_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $customers,
        ]);
    }

    public function setStatus(Request $request, $id)
    {
        if (!$this->canManage($request)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission.'
            ], 403);
        }

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $customer = Customer::findOrFail($id);
        $customer->update([
            'is_active' => $validated['is_active'],
        ]);

        return response()->json([
            'success' => true,
            'message' => $validated['is_active']
                ? 'Customer enabled successfully.'
                : 'Customer disabled successfully.',
            'data' => [
                'id' => $customer->id,
                'is_active' => (int) $customer->is_active,
            ],
        ]);
    }
}