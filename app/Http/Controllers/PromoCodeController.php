<?php

namespace App\Http\Controllers;

use App\Models\PromoCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class PromoCodeController extends Controller
{
    public function index(Request $request)
    {
        $query = PromoCode::query();

        if ($request->boolean('all')) {
            $promos = $query->orderBy('id', 'asc')->get();

            return response()->json([
                'success' => true,
                'promotions' => $promos,
            ]);
        }

        $promos = $query->orderBy('id', 'asc')->get();

        return response()->json([
            'success' => true,
            'promotions' => $promos,
        ]);
    }

    public function show($id)
    {
        $promo = PromoCode::findOrFail($id);

        return response()->json([
            'success' => true,
            'promotion' => $promo,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:promo_codes,code'],
            'discount_percent' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'first_order_only' => ['nullable', 'boolean'],
            'min_completed_orders' => ['nullable', 'integer', 'min:1'],
            'max_usage_per_customer' => ['nullable', 'integer', 'min:1'],
            'max_total_usage' => ['nullable', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $validated['code'] = strtoupper(trim($validated['code']));
        $validated['description'] = trim($validated['description']);
        $validated['first_order_only'] = $validated['first_order_only'] ?? false;
        $validated['used_count'] = 0;
        $validated['is_active'] = true;

        if ($validated['first_order_only'] && !empty($validated['min_completed_orders'])) {
            return response()->json([
                'success' => false,
                'message' => 'First order only cannot be combined with minimum completed orders.',
            ], 422);
        }

        $promo = PromoCode::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Promotion created successfully.',
            'promotion' => $promo,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $promo = PromoCode::findOrFail($id);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:promo_codes,code,' . $promo->id],
            'discount_percent' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'first_order_only' => ['nullable', 'boolean'],
            'min_completed_orders' => ['nullable', 'integer', 'min:1'],
            'max_usage_per_customer' => ['nullable', 'integer', 'min:1'],
            'max_total_usage' => ['nullable', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $validated['code'] = strtoupper(trim($validated['code']));
        $validated['description'] = trim($validated['description']);
        $validated['first_order_only'] = $validated['first_order_only'] ?? false;

        if ($validated['first_order_only'] && !empty($validated['min_completed_orders'])) {
            return response()->json([
                'success' => false,
                'message' => 'First order only cannot be combined with minimum completed orders.',
            ], 422);
        }

        $promo->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Promotion updated successfully.',
            'promotion' => $promo->fresh(),
        ]);
    }

    public function setStatus(Request $request, $id)
    {
        $promo = PromoCode::findOrFail($id);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $promo->update([
            'is_active' => $validated['is_active'],
        ]);

        return response()->json([
            'success' => true,
            'message' => $validated['is_active']
                ? 'Promotion enabled successfully.'
                : 'Promotion disabled successfully.',
        ]);
    }

    public function wallet(Request $request)
    {
        $customer = $request->user();

        $now = Carbon::now();

        $completedOrdersCount = DB::table('orders')
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['completed', 'delivered'])
            ->count();

        $promos = PromoCode::query()
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')
                ->orWhere('expires_at', '>=', $now);
            })
            ->orderBy('id', 'asc')
            ->get()
            ->filter(function ($promo) {
                if (!is_null($promo->max_total_usage) && $promo->used_count >= $promo->max_total_usage) {
                    return false;
                }
                return true;
            })
            ->values();

        $items = $promos->map(function ($promo) use ($customer, $completedOrdersCount) {
            $customerUsageCount = DB::table('orders')
                ->where('customer_id', $customer->id)
                ->where('promo_code', $promo->code)
                ->count();

            $status = 'eligible';
            $helperText = 'Ready to use on your next order';
            $usageText = $this->buildUsageText($promo, $customerUsageCount);

            if (!is_null($promo->max_usage_per_customer) && $customerUsageCount >= $promo->max_usage_per_customer) {
                $status = 'used_up';
                $helperText = 'You have used all available uses for this promo';
                $usageText = 'Usage limit: ' . $customerUsageCount . ' / ' . $promo->max_usage_per_customer . ' used';
            } elseif ($promo->first_order_only && $completedOrdersCount > 0) {
                $status = 'locked';
                $helperText = 'This promo is only available for your first completed order';
                $usageText = 'First order only';
            } elseif (!is_null($promo->min_completed_orders) && $completedOrdersCount < $promo->min_completed_orders) {
                $status = 'locked';
                $remaining = $promo->min_completed_orders - $completedOrdersCount;
                $helperText = 'You need ' . $remaining . ' more completed order' . ($remaining > 1 ? 's' : '') . ' to unlock this offer';
                $usageText = 'Unlock progress: ' . $completedOrdersCount . ' / ' . $promo->min_completed_orders . ' orders';
            }

            return [
                'id' => $promo->id,
                'code' => $promo->code,
                'title' => $this->buildPromoTitle($promo),
                'description' => $promo->description,
                'expiry_text' => $promo->expires_at
                    ? Carbon::parse($promo->expires_at)->format('d M Y')
                    : 'No expiry',
                'status' => $status,
                'usage_text' => $usageText,
                'helper_text' => $helperText,
                'discount_percent' => (float) $promo->discount_percent,
                'min_amount' => !is_null($promo->min_amount) ? (float) $promo->min_amount : 0,
                'first_order_only' => (bool) $promo->first_order_only,
                'min_completed_orders' => $promo->min_completed_orders,
                'max_usage_per_customer' => $promo->max_usage_per_customer,
                'max_total_usage' => $promo->max_total_usage,
                'used_count' => (int) $promo->used_count,
                'customer_used_count' => $customerUsageCount,
                'unlock_progress' => !is_null($promo->min_completed_orders)
                    ? min($completedOrdersCount / max($promo->min_completed_orders, 1), 1)
                    : null,
            ];
        });

        return response()->json([
            'success' => true,
            'promotions' => $items,
            'summary' => [
                'eligible_count' => $items->where('status', 'eligible')->count(),
                'locked_count' => $items->where('status', 'locked')->count(),
                'used_up_count' => $items->where('status', 'used_up')->count(),
                'total_count' => $items->count(),
            ],
        ]);
    }

    private function buildPromoTitle($promo): string
    {
        $parts = [];

        $parts[] = rtrim(rtrim(number_format((float) $promo->discount_percent, 2, '.', ''), '0'), '.') . '% off';

        if (!is_null($promo->min_amount) && (float) $promo->min_amount > 0) {
            $parts[] = 'on orders from $' . number_format((float) $promo->min_amount, 2);
        }

        if ($promo->first_order_only) {
            $parts[] = 'for first order';
        } elseif (!is_null($promo->min_completed_orders)) {
            $parts[] = 'after ' . $promo->min_completed_orders . ' completed orders';
        }

        return ucfirst(implode(' ', $parts));
    }

    private function buildUsageText($promo, int $customerUsageCount): string
    {
        if (!is_null($promo->max_usage_per_customer)) {
            $left = max($promo->max_usage_per_customer - $customerUsageCount, 0);
            return $left . ' use' . ($left != 1 ? 's' : '') . ' left';
        }

        if (!is_null($promo->max_total_usage)) {
            $left = max($promo->max_total_usage - (int) $promo->used_count, 0);
            return $left . ' total use' . ($left != 1 ? 's' : '') . ' left';
        }

        return 'Available now';
    }
    public function validateForCheckout(Request $request)
    {
        $customer = $request->user();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        $code = strtoupper(trim($validated['code']));
        $subtotal = (float) $validated['subtotal'];
        $now = Carbon::now();

        $promo = PromoCode::query()
            ->whereRaw('UPPER(code) = ?', [$code])
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')
                ->orWhere('expires_at', '>=', $now);
            })
            ->first();

        if (!$promo) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code not found or expired.',
            ], 404);
        }

        if (!is_null($promo->max_total_usage) && $promo->used_count >= $promo->max_total_usage) {
            return response()->json([
                'success' => false,
                'message' => 'This promo code has reached its total usage limit.',
            ], 422);
        }

        $completedOrdersCount = DB::table('orders')
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['completed', 'delivered'])
            ->count();

        $customerUsageCount = DB::table('promo_code_usages')
            ->where('promo_code_id', $promo->id)
            ->where('customer_id', $customer->id)
            ->count();

        if (!is_null($promo->max_usage_per_customer) && $customerUsageCount >= $promo->max_usage_per_customer) {
            return response()->json([
                'success' => false,
                'message' => 'You have already used this promo code the maximum number of times.',
            ], 422);
        }

        if ($promo->first_order_only && $completedOrdersCount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'This promo code is only available for your first completed order.',
            ], 422);
        }

        if (!is_null($promo->min_completed_orders) && $completedOrdersCount < $promo->min_completed_orders) {
            $remaining = $promo->min_completed_orders - $completedOrdersCount;

            return response()->json([
                'success' => false,
                'message' => 'You need ' . $remaining . ' more completed order' . ($remaining > 1 ? 's' : '') . ' to unlock this promo.',
            ], 422);
        }

        if (!is_null($promo->min_amount) && $subtotal < (float) $promo->min_amount) {
            return response()->json([
                'success' => false,
                'message' => 'Minimum order for this promo is $' . number_format((float) $promo->min_amount, 2) . '.',
            ], 422);
        }

        $discountAmount = round($subtotal * ((float) $promo->discount_percent / 100), 2);

        return response()->json([
            'success' => true,
            'message' => 'Promo code is valid.',
            'promotion' => [
                'id' => (int) $promo->id,
                'code' => $promo->code,
                'discount_percent' => (float) $promo->discount_percent,
                'min_amount' => !is_null($promo->min_amount) ? (float) $promo->min_amount : 0,
                'discount_amount' => $discountAmount,
            ],
        ]);
    }
}