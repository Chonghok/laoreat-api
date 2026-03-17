<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    public function show(Request $request)
    {
        $customer = $request->user();

        $cart = DB::table('carts')
            ->where('customer_id', $customer->id)
            ->first();

        if (!$cart) {
            return response()->json([
                'success' => true,
                'data' => [
                    'cart_id' => null,
                    'items' => [],
                ],
            ]);
        }

        $items = DB::table('cart_items as ci')
            ->join('products as p', 'p.id', '=', 'ci.product_id')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->select(
                'ci.id as cart_item_id',
                'ci.cart_id',
                'ci.quantity',
                'ci.created_at',
                'ci.updated_at',
                'p.id as product_id',
                'p.category_id',
                'p.name',
                'p.price',
                'p.unit_label',
                'p.image_url',
                'p.description',
                'p.is_active',
                'p.is_available',
                'p.discount_active',
                'p.discount_percent',
                'c.name as category_name',
                'c.is_active as category_is_active'
            )
            ->where('ci.cart_id', $cart->id)
            ->orderBy('ci.id', 'asc')
            ->get()
            ->map(function ($item) {
                $basePrice = (float) $item->price;
                $qty = (int) $item->quantity;
                $discountActive = (bool) $item->discount_active;
                $discountPercent = $item->discount_percent !== null ? (float) $item->discount_percent : null;

                $finalPrice = $basePrice;

                if ($discountActive && $discountPercent !== null && $discountPercent > 0) {
                    $finalPrice = round($basePrice * (1 - ($discountPercent / 100)), 2);
                }

                $warning = null;

                if (!$item->is_active || !$item->category_is_active) {
                    $warning = 'This item is no longer available. Please remove it before checkout.';
                } elseif (!$item->is_available) {
                    $warning = 'This item is out of stock. Please remove it before checkout.';
                }

                return [
                    'cart_item_id' => $item->cart_item_id,
                    'cart_id' => $item->cart_id,
                    'quantity' => $qty,
                    'product_id' => $item->product_id,
                    'category_id' => $item->category_id,
                    'category_name' => $item->category_name,
                    'name' => $item->name,
                    'price' => $basePrice,
                    'final_price' => $finalPrice,
                    'unit_label' => $item->unit_label,
                    'image_url' => $item->image_url,
                    'description' => $item->description,
                    'is_active' => (bool) $item->is_active,
                    'is_available' => (bool) $item->is_available,
                    'discount_active' => (bool) $item->discount_active,
                    'discount_percent' => $item->discount_percent,
                    'subtotal' => round($finalPrice * $qty, 2),
                    'warning' => $warning,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'cart_id' => $cart->id,
                'items' => $items,
            ],
        ]);
    }

    public function addItem(Request $request)
    {
        $customer = $request->user();

        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'nullable|integer|min:1',
        ]);

        $quantity = (int) ($request->quantity ?? 1);

        $product = DB::table('products as p')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->select(
                'p.id',
                'p.is_active',
                'p.is_available',
                'c.is_active as category_is_active'
            )
            ->where('p.id', $request->product_id)
            ->first();

        if (!$product || !$product->is_active || !$product->category_is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found or inactive.',
            ], 404);
        }

        if (!$product->is_available) {
            return response()->json([
                'success' => false,
                'message' => 'This product is currently out of stock.',
            ], 422);
        }

        $cart = DB::table('carts')
            ->where('customer_id', $customer->id)
            ->first();

        if (!$cart) {
            $cartId = DB::table('carts')->insertGetId([
                'customer_id' => $customer->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $cart = DB::table('carts')->where('id', $cartId)->first();
        }

        $existingItem = DB::table('cart_items')
            ->where('cart_id', $cart->id)
            ->where('product_id', $request->product_id)
            ->first();

        if ($existingItem) {
            DB::table('cart_items')
                ->where('id', $existingItem->id)
                ->update([
                    'quantity' => (int) $existingItem->quantity + $quantity,
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Cart quantity updated.',
            ]);
        }

        DB::table('cart_items')->insert([
            'cart_id' => $cart->id,
            'product_id' => (int) $request->product_id,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product added to cart.',
        ], 201);
    }

    public function updateItem(Request $request, $id)
    {
        $customer = $request->user();

        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $cartItem = DB::table('cart_items as ci')
            ->join('carts as c', 'c.id', '=', 'ci.cart_id')
            ->join('products as p', 'p.id', '=', 'ci.product_id')
            ->join('categories as cat', 'cat.id', '=', 'p.category_id')
            ->select(
                'ci.id',
                'ci.cart_id',
                'ci.product_id',
                'c.customer_id',
                'p.is_active',
                'p.is_available',
                'cat.is_active as category_is_active'
            )
            ->where('ci.id', $id)
            ->where('c.customer_id', $customer->id)
            ->first();

        if (!$cartItem) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found.',
            ], 404);
        }

        if (!$cartItem->is_active || !$cartItem->category_is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This item is no longer available.',
            ], 422);
        }

        if (!$cartItem->is_available) {
            return response()->json([
                'success' => false,
                'message' => 'This item is currently out of stock.',
            ], 422);
        }

        DB::table('cart_items')
            ->where('id', $id)
            ->update([
                'quantity' => (int) $request->quantity,
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Cart item updated.',
        ]);
    }

    public function removeItem(Request $request, $id)
    {
        $customer = $request->user();

        $cartItem = DB::table('cart_items as ci')
            ->join('carts as c', 'c.id', '=', 'ci.cart_id')
            ->select('ci.id')
            ->where('ci.id', $id)
            ->where('c.customer_id', $customer->id)
            ->first();

        if (!$cartItem) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found.',
            ], 404);
        }

        DB::table('cart_items')
            ->where('id', $id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Item removed from cart.',
        ]);
    }

    public function clear(Request $request)
    {
        $customer = $request->user();

        $cart = DB::table('carts')
            ->where('customer_id', $customer->id)
            ->first();

        if (!$cart) {
            return response()->json([
                'success' => true,
                'message' => 'Cart is already empty.',
            ]);
        }

        DB::table('cart_items')
            ->where('cart_id', $cart->id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cart cleared successfully.',
        ]);
    }
}