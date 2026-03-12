<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FavoriteController extends Controller
{
    public function index(Request $request)
    {
        $customer = $request->user();

        $favorites = DB::table('favorites as f')
            ->join('products as p', 'p.id', '=', 'f.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->select(
                'f.id as favorite_id',
                'f.created_at as favorited_at',
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
                'c.name as category_name'
            )
            ->where('f.customer_id', $customer->id)
            ->orderBy('f.id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $favorites,
        ]);
    }

    public function store(Request $request)
    {
        $customer = $request->user();

        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $product = DB::table('products')
            ->where('id', $request->product_id)
            ->first();

        if (!$product || (int) $product->is_active !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found or unavailable.',
            ], 404);
        }

        $exists = DB::table('favorites')
            ->where('customer_id', $customer->id)
            ->where('product_id', $request->product_id)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => true,
                'message' => 'Product is already in favorites.',
            ]);
        }

        DB::table('favorites')->insert([
            'customer_id' => $customer->id,
            'product_id' => (int) $request->product_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product added to favorites.',
        ], 201);
    }

    public function destroy(Request $request, $productId)
    {
        $customer = $request->user();

        $deleted = DB::table('favorites')
            ->where('customer_id', $customer->id)
            ->where('product_id', $productId)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Favorite item not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product removed from favorites.',
        ]);
    }
}