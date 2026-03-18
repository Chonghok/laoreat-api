<?php

namespace App\Http\Controllers;

use App\Models\PromoCode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

class OrderController extends Controller
{
    public function createStripeIntent(Request $request)
    {
        $customer = $request->user();

        $validated = $request->validate([
            'delivery_type_id' => ['required', 'integer', 'exists:delivery_types,id'],
            'promo_code' => ['nullable', 'string', 'max:50'],

            'contact_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:50'],

            'delivery_address' => ['nullable', 'string'],
            'delivery_lat' => ['nullable', 'numeric'],
            'delivery_lng' => ['nullable', 'numeric'],
            'scheduled_for' => ['nullable', 'date'],
            'note_for_rider' => ['nullable', 'string', 'max:500'],
        ]);

        $checkout = $this->prepareCheckout($customer->id, $validated);

        $stripe = new StripeClient(config('services.stripe.secret'));

        $intent = $stripe->paymentIntents->create([
            'amount' => (int) round($checkout['total_amount'] * 100),
            'currency' => config('services.stripe.currency', 'usd'),
            'payment_method_types' => ['card'],
            'metadata' => [
                'customer_id' => (string) $customer->id,
                'delivery_type_id' => (string) $validated['delivery_type_id'],
                'promo_code' => $checkout['promo_code'] ?? '',
                'cart_total' => (string) $checkout['total_amount'],
            ],
        ]);

        return response()->json([
            'success' => true,
            'client_secret' => $intent->client_secret,
            'payment_intent_id' => $intent->id,
            'amount' => $checkout['total_amount'],
            'currency' => strtoupper(config('services.stripe.currency', 'usd')),
        ]);
    }

    public function store(Request $request)
    {
        $customer = $request->user();

        $validated = $request->validate([
            'delivery_type_id' => ['required', 'integer', 'exists:delivery_types,id'],
            'payment_method' => ['required', 'string', 'in:cash,card'],
            'promo_code' => ['nullable', 'string', 'max:50'],

            'contact_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:50'],

            'delivery_address' => ['nullable', 'string'],
            'delivery_lat' => ['nullable', 'numeric'],
            'delivery_lng' => ['nullable', 'numeric'],
            'scheduled_for' => ['nullable', 'date'],
            'note_for_rider' => ['nullable', 'string', 'max:500'],

            'stripe_payment_intent_id' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validated['payment_method'] === 'card' && empty($validated['stripe_payment_intent_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Stripe payment intent is required for card payments.',
            ], 422);
        }

        $checkout = $this->prepareCheckout($customer->id, $validated);

        $stripePaymentData = [
            'intent_id' => null,
            'card_brand' => null,
            'card_last4' => null,
            'paid_at' => null,
        ];

        if ($validated['payment_method'] === 'card') {
            $stripe = new StripeClient(config('services.stripe.secret'));

            $intent = $stripe->paymentIntents->retrieve($validated['stripe_payment_intent_id'], []);

            if (!$intent || $intent->status !== 'succeeded') {
                return response()->json([
                    'success' => false,
                    'message' => 'Card payment was not completed.',
                ], 422);
            }

            $expectedAmount = (int) round($checkout['total_amount'] * 100);

            if ((int) $intent->amount_received < $expectedAmount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Paid amount does not match the order total.',
                ], 422);
            }

            $paymentMethod = null;

            if (!empty($intent->payment_method)) {
                $paymentMethod = $stripe->paymentMethods->retrieve($intent->payment_method, []);
            }

            $stripePaymentData = [
                'intent_id' => $intent->id,
                'card_brand' => $paymentMethod && isset($paymentMethod->card)
                    ? $paymentMethod->card->brand
                    : null,
                'card_last4' => $paymentMethod && isset($paymentMethod->card)
                    ? $paymentMethod->card->last4
                    : null,
                'paid_at' => now(),
            ];
        }

        $order = DB::transaction(function () use (
            $customer,
            $validated,
            $checkout,
            $stripePaymentData
        ) {
            $orderId = DB::table('orders')->insertGetId([
                'customer_id' => $customer->id,
                'delivery_type_id' => $checkout['delivery_type']->id,
                'order_number' => $this->generateOrderNumber(),
                'subtotal' => $checkout['subtotal'],
                'promo_code' => $checkout['promo_code'],
                'promo_discount_percent' => $checkout['promo_discount_percent'],
                'discount_amount' => $checkout['discount_amount'],
                'delivery_fee' => $checkout['delivery_fee'],
                'total_amount' => $checkout['total_amount'],
                'status' => 'accepted',
                'payment_method' => $validated['payment_method'],
                'contact_name' => $validated['contact_name'],
                'contact_phone' => $validated['contact_phone'],
                'delivery_address' => $checkout['is_pickup'] ? null : $validated['delivery_address'],
                'delivery_lat' => $checkout['is_pickup'] ? null : $validated['delivery_lat'],
                'delivery_lng' => $checkout['is_pickup'] ? null : $validated['delivery_lng'],
                'scheduled_for' => $validated['scheduled_for'] ?? null,
                'note_for_rider' => $validated['note_for_rider'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($checkout['prepared_items'] as $item) {
                DB::table('order_items')->insert([
                    'order_id' => $orderId,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'unit_price' => $item['unit_price'],
                    'discount_percent' => $item['discount_percent'],
                    'final_unit_price' => $item['final_unit_price'],
                    'quantity' => $item['quantity'],
                    'line_total' => $item['line_total'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('payments')->insert([
                'order_id' => $orderId,
                'customer_id' => $customer->id,
                'method' => $validated['payment_method'],
                'provider' => $validated['payment_method'] === 'cash' ? 'cash' : 'stripe',
                'amount' => $checkout['total_amount'],
                'currency' => strtoupper(config('services.stripe.currency', 'usd')),
                'status' => $validated['payment_method'] === 'cash' ? 'pending' : 'paid',
                'stripe_payment_intent_id' => $validated['payment_method'] === 'cash'
                    ? null
                    : $stripePaymentData['intent_id'],
                'card_brand' => $validated['payment_method'] === 'cash'
                    ? null
                    : $stripePaymentData['card_brand'],
                'card_last4' => $validated['payment_method'] === 'cash'
                    ? null
                    : $stripePaymentData['card_last4'],
                'paid_at' => $validated['payment_method'] === 'cash'
                    ? null
                    : $stripePaymentData['paid_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($checkout['promo']) {
                DB::table('promo_code_usages')->insert([
                    'promo_code_id' => $checkout['promo']->id,
                    'customer_id' => $customer->id,
                    'order_id' => $orderId,
                    'used_at' => now(),
                ]);

                DB::table('promo_codes')
                    ->where('id', $checkout['promo']->id)
                    ->increment('used_count');
            }

            DB::table('cart_items')
                ->where('cart_id', $checkout['cart']->id)
                ->delete();

            return DB::table('orders')
                ->select('id', 'order_number', 'status', 'total_amount')
                ->where('id', $orderId)
                ->first();
        });

        return response()->json([
            'success' => true,
            'message' => 'Order placed successfully.',
            'order' => [
                'id' => (int) $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'total_amount' => (float) $order->total_amount,
            ],
        ], 201);
    }

    private function prepareCheckout(int $customerId, array $validated): array
    {
        $deliveryType = DB::table('delivery_types')
            ->where('id', $validated['delivery_type_id'])
            ->where('is_active', true)
            ->first();

        if (!$deliveryType) {
            abort(response()->json([
                'success' => false,
                'message' => 'Delivery type not found or inactive.',
            ], 422));
        }

        $deliveryCode = strtoupper($deliveryType->code);
        $isPickup = $deliveryCode === 'PICKUP';

        if (!$isPickup) {
            if (
                empty($validated['delivery_address']) ||
                !isset($validated['delivery_lat']) ||
                !isset($validated['delivery_lng'])
            ) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Delivery address and map coordinates are required.',
                ], 422));
            }
        }

        if ($deliveryCode === 'SCHEDULED' && empty($validated['scheduled_for'])) {
            abort(response()->json([
                'success' => false,
                'message' => 'Scheduled delivery requires a scheduled date and time.',
            ], 422));
        }

        $cart = DB::table('carts')
            ->where('customer_id', $customerId)
            ->first();

        if (!$cart) {
            abort(response()->json([
                'success' => false,
                'message' => 'Your cart is empty.',
            ], 422));
        }

        $cartItems = DB::table('cart_items as ci')
            ->join('products as p', 'p.id', '=', 'ci.product_id')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->select(
                'ci.id as cart_item_id',
                'ci.quantity',
                'p.id as product_id',
                'p.name',
                'p.price',
                'p.discount_active',
                'p.discount_percent',
                'p.is_active',
                'p.is_available',
                'c.is_active as category_is_active'
            )
            ->where('ci.cart_id', $cart->id)
            ->orderBy('ci.id', 'asc')
            ->get();

        if ($cartItems->isEmpty()) {
            abort(response()->json([
                'success' => false,
                'message' => 'Your cart is empty.',
            ], 422));
        }

        foreach ($cartItems as $item) {
            if (!$item->is_active || !$item->category_is_active) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'One or more items are no longer available.',
                ], 422));
            }

            if (!$item->is_available) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'One or more items are out of stock.',
                ], 422));
            }
        }

        $subtotal = 0;
        $preparedItems = [];

        foreach ($cartItems as $item) {
            $basePrice = (float) $item->price;
            $discountPercent = ($item->discount_active && !is_null($item->discount_percent))
                ? (float) $item->discount_percent
                : null;

            $finalUnitPrice = $basePrice;

            if (!is_null($discountPercent) && $discountPercent > 0) {
                $finalUnitPrice = round($basePrice * (1 - ($discountPercent / 100)), 2);
            }

            $lineTotal = round($finalUnitPrice * (int) $item->quantity, 2);
            $subtotal += $lineTotal;

            $preparedItems[] = [
                'product_id' => (int) $item->product_id,
                'product_name' => $item->name,
                'unit_price' => $basePrice,
                'discount_percent' => $discountPercent,
                'final_unit_price' => $finalUnitPrice,
                'quantity' => (int) $item->quantity,
                'line_total' => $lineTotal,
            ];
        }

        $subtotal = round($subtotal, 2);

        $promo = null;
        $promoCode = null;
        $promoDiscountPercent = null;
        $discountAmount = 0.00;

        if (!empty($validated['promo_code'])) {
            $promoCodeInput = strtoupper(trim($validated['promo_code']));
            $now = Carbon::now();

            $promo = PromoCode::query()
                ->whereRaw('UPPER(code) = ?', [$promoCodeInput])
                ->where('is_active', true)
                ->where(function ($q) use ($now) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>=', $now);
                })
                ->first();

            if (!$promo) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Promo code not found or expired.',
                ], 422));
            }

            if (!is_null($promo->max_total_usage) && $promo->used_count >= $promo->max_total_usage) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'This promo code has reached its total usage limit.',
                ], 422));
            }

            $completedOrdersCount = DB::table('orders')
                ->where('customer_id', $customerId)
                ->whereIn('status', ['delivered', 'picked_up'])
                ->count();

            $customerUsageCount = DB::table('promo_code_usages')
                ->where('promo_code_id', $promo->id)
                ->where('customer_id', $customerId)
                ->count();

            if (!is_null($promo->max_usage_per_customer) && $customerUsageCount >= $promo->max_usage_per_customer) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'You have already used this promo code the maximum number of times.',
                ], 422));
            }

            if ($promo->first_order_only && $completedOrdersCount > 0) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'This promo code is only available for your first completed order.',
                ], 422));
            }

            if (!is_null($promo->min_completed_orders) && $completedOrdersCount < $promo->min_completed_orders) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'This promo code is not unlocked yet.',
                ], 422));
            }

            if (!is_null($promo->min_amount) && $subtotal < (float) $promo->min_amount) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Minimum order for this promo is $' . number_format((float) $promo->min_amount, 2) . '.',
                ], 422));
            }

            $promoCode = $promo->code;
            $promoDiscountPercent = (float) $promo->discount_percent;
            $discountAmount = round($subtotal * ($promoDiscountPercent / 100), 2);
        }

        $deliveryFee = $isPickup ? 0.00 : (float) $deliveryType->fee;
        $totalAmount = round($subtotal - $discountAmount + $deliveryFee, 2);

        return [
            'cart' => $cart,
            'delivery_type' => $deliveryType,
            'is_pickup' => $isPickup,
            'subtotal' => $subtotal,
            'promo' => $promo,
            'promo_code' => $promoCode,
            'promo_discount_percent' => $promoDiscountPercent,
            'discount_amount' => $discountAmount,
            'delivery_fee' => $deliveryFee,
            'total_amount' => $totalAmount,
            'prepared_items' => $preparedItems,
        ];
    }

    private function generateOrderNumber(): string
    {
        return 'ORD-' . now()->format('ymd') . '-' . strtoupper(substr(uniqid(), -4));
    }

    public function customerOrders(Request $request)
    {
        $customer = $request->user();

        $orders = DB::table('orders as o')
            ->join('delivery_types as dt', 'dt.id', '=', 'o.delivery_type_id')
            ->select(
                'o.id',
                'o.order_number',
                'o.status',
                'o.subtotal',
                'o.discount_amount',
                'o.delivery_fee',
                'o.total_amount',
                'o.payment_method',
                'o.contact_name',
                'o.contact_phone',
                'o.delivery_address',
                'o.scheduled_for',
                'o.note_for_rider',
                'o.created_at',
                'dt.name as delivery_type_name',
                'dt.code as delivery_type_code',
                DB::raw('(SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = o.id) as item_count')
            )
            ->where('o.customer_id', $customer->id)
            ->orderByDesc('o.created_at')
            ->orderByDesc('o.id')
            ->get();

        return response()->json([
            'success' => true,
            'orders' => $orders,
        ]);
    }

    public function customerOrderDetail(Request $request, $id)
    {
        $customer = $request->user();

        $order = DB::table('orders as o')
            ->join('delivery_types as dt', 'dt.id', '=', 'o.delivery_type_id')
            ->select(
                'o.id',
                'o.order_number',
                'o.status',
                'o.subtotal',
                'o.promo_code',
                'o.promo_discount_percent',
                'o.discount_amount',
                'o.delivery_fee',
                'o.total_amount',
                'o.payment_method',
                'o.contact_name',
                'o.contact_phone',
                'o.delivery_address',
                'o.delivery_lat',
                'o.delivery_lng',
                'o.scheduled_for',
                'o.note_for_rider',
                'o.created_at',
                'o.updated_at',
                'dt.name as delivery_type_name',
                'dt.code as delivery_type_code'
            )
            ->where('o.id', $id)
            ->where('o.customer_id', $customer->id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $items = DB::table('order_items')
            ->select(
                'id',
                'product_id',
                'product_name',
                'unit_price',
                'discount_percent',
                'final_unit_price',
                'quantity',
                'line_total'
            )
            ->where('order_id', $order->id)
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'order' => [
                'id' => (int) $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'subtotal' => (float) $order->subtotal,
                'promo_code' => $order->promo_code,
                'promo_discount_percent' => is_null($order->promo_discount_percent)
                    ? null
                    : (float) $order->promo_discount_percent,
                'discount_amount' => (float) $order->discount_amount,
                'delivery_fee' => (float) $order->delivery_fee,
                'total_amount' => (float) $order->total_amount,
                'payment_method' => $order->payment_method,
                'contact_name' => $order->contact_name,
                'contact_phone' => $order->contact_phone,
                'delivery_address' => $order->delivery_address,
                'delivery_lat' => $order->delivery_lat,
                'delivery_lng' => $order->delivery_lng,
                'scheduled_for' => $order->scheduled_for,
                'note_for_rider' => $order->note_for_rider,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
                'delivery_type_name' => $order->delivery_type_name,
                'delivery_type_code' => $order->delivery_type_code,
                'items' => $items->map(function ($item) {
                    return [
                        'id' => (int) $item->id,
                        'product_id' => (int) $item->product_id,
                        'product_name' => $item->product_name,
                        'unit_price' => (float) $item->unit_price,
                        'discount_percent' => is_null($item->discount_percent)
                            ? null
                            : (float) $item->discount_percent,
                        'final_unit_price' => (float) $item->final_unit_price,
                        'quantity' => (int) $item->quantity,
                        'line_total' => (float) $item->line_total,
                    ];
                })->values(),
            ],
        ]);
    }

    public function adminOrders(Request $request)
    {
        $admin = $request->user();

        $role = strtolower($admin->role ?? '');
        if (!in_array($role, ['superadmin', 'manager', 'operator'])) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission.',
            ], 403);
        }

        $orders = DB::table('orders as o')
            ->join('delivery_types as dt', 'dt.id', '=', 'o.delivery_type_id')
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('payments as p', 'p.order_id', '=', 'o.id')
            ->select(
                'o.id',
                'o.customer_id',
                'o.delivery_type_id',
                'o.order_number',
                'o.subtotal',
                'o.promo_code',
                'o.promo_discount_percent',
                'o.discount_amount',
                'o.delivery_fee',
                'o.total_amount',
                'o.status',
                'o.payment_method',
                'o.contact_name',
                'o.contact_phone',
                'o.delivery_address',
                'o.delivery_lat',
                'o.delivery_lng',
                'o.scheduled_for',
                'o.note_for_rider',
                'o.created_at',
                'o.updated_at',
                'dt.name as delivery_type_name',
                'dt.code as delivery_type_code',
                'c.email as customer_email',
                'p.method as payment_method_db',
                'p.provider as payment_provider',
                'p.status as payment_status',
                'p.paid_at'
            )
            ->orderByDesc('o.created_at')
            ->orderByDesc('o.id')
            ->get();

        $orderIds = $orders->pluck('id')->values();

        $items = DB::table('order_items')
            ->select(
                'id',
                'order_id',
                'product_id',
                'product_name',
                'unit_price',
                'discount_percent',
                'final_unit_price',
                'quantity',
                'line_total'
            )
            ->whereIn('order_id', $orderIds)
            ->orderBy('id', 'asc')
            ->get()
            ->groupBy('order_id');

        $mapped = $orders->map(function ($order) use ($items) {
            $orderItems = $items->get($order->id, collect())->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'order_id' => (int) $item->order_id,
                    'product_id' => (int) $item->product_id,
                    'product_name' => $item->product_name,
                    'unit_price' => (float) $item->unit_price,
                    'discount_percent' => is_null($item->discount_percent) ? null : (float) $item->discount_percent,
                    'final_unit_price' => (float) $item->final_unit_price,
                    'quantity' => (int) $item->quantity,
                    'line_total' => (float) $item->line_total,
                ];
            })->values();

            return [
                'id' => (int) $order->id,
                'customer_id' => (int) $order->customer_id,
                'delivery_type_id' => (int) $order->delivery_type_id,
                'order_number' => $order->order_number,
                'subtotal' => (float) $order->subtotal,
                'promo_code' => $order->promo_code,
                'promo_discount_percent' => is_null($order->promo_discount_percent) ? null : (float) $order->promo_discount_percent,
                'discount_amount' => (float) $order->discount_amount,
                'delivery_fee' => (float) $order->delivery_fee,
                'total_amount' => (float) $order->total_amount,
                'status' => $order->status,
                'payment_method' => $order->payment_method,
                'contact_name' => $order->contact_name,
                'contact_phone' => $order->contact_phone,
                'delivery_address' => $order->delivery_address,
                'delivery_lat' => $order->delivery_lat,
                'delivery_lng' => $order->delivery_lng,
                'scheduled_for' => $order->scheduled_for,
                'note_for_rider' => $order->note_for_rider,
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
                'delivery_type_name' => $order->delivery_type_name,
                'delivery_type_code' => $order->delivery_type_code,
                'customer_email' => $order->customer_email,
                'payment_provider' => $order->payment_provider,
                'payment_status' => $order->payment_status,
                'paid_at' => $order->paid_at,
                'item_count' => $orderItems->sum('quantity'),
                'items' => $orderItems,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'orders' => $mapped,
        ]);
    }

    public function updateAdminOrderStatus(Request $request, $id)
    {
        $admin = $request->user();

        $role = strtolower($admin->role ?? '');
        if (!in_array($role, ['superadmin', 'manager', 'operator'])) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission.',
            ], 403);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'max:50'],
        ]);

        $order = DB::table('orders as o')
            ->join('delivery_types as dt', 'dt.id', '=', 'o.delivery_type_id')
            ->leftJoin('payments as p', 'p.order_id', '=', 'o.id')
            ->select(
                'o.id',
                'o.customer_id',
                'o.delivery_type_id',
                'o.order_number',
                'o.subtotal',
                'o.promo_code',
                'o.promo_discount_percent',
                'o.discount_amount',
                'o.delivery_fee',
                'o.total_amount',
                'o.status',
                'o.payment_method',
                'o.contact_name',
                'o.contact_phone',
                'o.delivery_address',
                'o.delivery_lat',
                'o.delivery_lng',
                'o.scheduled_for',
                'o.note_for_rider',
                'o.created_at',
                'o.updated_at',
                'dt.name as delivery_type_name',
                'dt.code as delivery_type_code',
                'p.provider as payment_provider',
                'p.status as payment_status',
                'p.paid_at'
            )
            ->where('o.id', $id)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404);
        }

        $typeCode = strtoupper($order->delivery_type_code);
        $currentStatus = strtolower($order->status);
        $newStatus = strtolower($validated['status']);

        $allowedFlow = $typeCode === 'PICKUP'
            ? ['accepted', 'preparing', 'ready_for_pickup', 'picked_up']
            : ['accepted', 'preparing', 'on_the_way', 'delivered'];

        if (!in_array($newStatus, $allowedFlow, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid status for this order type.',
            ], 422);
        }

        $currentIndex = array_search($currentStatus, $allowedFlow, true);
        $newIndex = array_search($newStatus, $allowedFlow, true);

        if ($currentIndex === false || $newIndex === false || $newIndex !== $currentIndex + 1) {
            return response()->json([
                'success' => false,
                'message' => 'Order status must follow the next step in sequence only.',
            ], 422);
        }

        DB::transaction(function () use ($order, $newStatus) {
            DB::table('orders')
                ->where('id', $order->id)
                ->update([
                    'status' => $newStatus,
                    'updated_at' => now(),
                ]);

            $shouldMarkCashPaid = in_array($newStatus, ['delivered', 'picked_up'], true);

            if (strtolower($order->payment_method) === 'cash' && $shouldMarkCashPaid) {
                DB::table('payments')
                    ->where('order_id', $order->id)
                    ->where('method', 'cash')
                    ->update([
                        'status' => 'paid',
                        'paid_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        });

        $updatedOrder = DB::table('orders as o')
            ->join('delivery_types as dt', 'dt.id', '=', 'o.delivery_type_id')
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('payments as p', 'p.order_id', '=', 'o.id')
            ->select(
                'o.id',
                'o.customer_id',
                'o.delivery_type_id',
                'o.order_number',
                'o.subtotal',
                'o.promo_code',
                'o.promo_discount_percent',
                'o.discount_amount',
                'o.delivery_fee',
                'o.total_amount',
                'o.status',
                'o.payment_method',
                'o.contact_name',
                'o.contact_phone',
                'o.delivery_address',
                'o.delivery_lat',
                'o.delivery_lng',
                'o.scheduled_for',
                'o.note_for_rider',
                'o.created_at',
                'o.updated_at',
                'dt.name as delivery_type_name',
                'dt.code as delivery_type_code',
                'c.email as customer_email',
                'p.provider as payment_provider',
                'p.status as payment_status',
                'p.paid_at'
            )
            ->where('o.id', $order->id)
            ->first();

        $items = DB::table('order_items')
            ->select(
                'id',
                'order_id',
                'product_id',
                'product_name',
                'unit_price',
                'discount_percent',
                'final_unit_price',
                'quantity',
                'line_total'
            )
            ->where('order_id', $order->id)
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'order_id' => (int) $item->order_id,
                    'product_id' => (int) $item->product_id,
                    'product_name' => $item->product_name,
                    'unit_price' => (float) $item->unit_price,
                    'discount_percent' => is_null($item->discount_percent) ? null : (float) $item->discount_percent,
                    'final_unit_price' => (float) $item->final_unit_price,
                    'quantity' => (int) $item->quantity,
                    'line_total' => (float) $item->line_total,
                ];
            })->values();

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully.',
            'order' => [
                'id' => (int) $updatedOrder->id,
                'customer_id' => (int) $updatedOrder->customer_id,
                'delivery_type_id' => (int) $updatedOrder->delivery_type_id,
                'order_number' => $updatedOrder->order_number,
                'subtotal' => (float) $updatedOrder->subtotal,
                'promo_code' => $updatedOrder->promo_code,
                'promo_discount_percent' => is_null($updatedOrder->promo_discount_percent) ? null : (float) $updatedOrder->promo_discount_percent,
                'discount_amount' => (float) $updatedOrder->discount_amount,
                'delivery_fee' => (float) $updatedOrder->delivery_fee,
                'total_amount' => (float) $updatedOrder->total_amount,
                'status' => $updatedOrder->status,
                'payment_method' => $updatedOrder->payment_method,
                'contact_name' => $updatedOrder->contact_name,
                'contact_phone' => $updatedOrder->contact_phone,
                'delivery_address' => $updatedOrder->delivery_address,
                'delivery_lat' => $updatedOrder->delivery_lat,
                'delivery_lng' => $updatedOrder->delivery_lng,
                'scheduled_for' => $updatedOrder->scheduled_for,
                'note_for_rider' => $updatedOrder->note_for_rider,
                'created_at' => $updatedOrder->created_at,
                'updated_at' => $updatedOrder->updated_at,
                'delivery_type_name' => $updatedOrder->delivery_type_name,
                'delivery_type_code' => $updatedOrder->delivery_type_code,
                'customer_email' => $updatedOrder->customer_email,
                'payment_provider' => $updatedOrder->payment_provider,
                'payment_status' => $updatedOrder->payment_status,
                'paid_at' => $updatedOrder->paid_at,
                'item_count' => $items->sum('quantity'),
                'items' => $items,
            ],
        ]);
    }
}