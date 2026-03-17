<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductReviewController extends Controller
{
    public function productsWithReviewStats(Request $request)
    {
        $products = Product::select(
                'products.id',
                'products.name',
                'products.image_url'
            )
            ->leftJoin('product_reviews', 'products.id', '=', 'product_reviews.product_id')
            ->selectRaw('COUNT(product_reviews.id) as total_reviews')
            ->selectRaw('COALESCE(SUM(CASE WHEN product_reviews.is_visible THEN 1 ELSE 0 END), 0) as visible_reviews')
            ->selectRaw('COALESCE(SUM(CASE WHEN NOT product_reviews.is_visible THEN 1 ELSE 0 END), 0) as hidden_reviews')
            ->selectRaw('COALESCE(ROUND(AVG(CASE WHEN product_reviews.is_visible THEN product_reviews.rating END), 1), 0) as average_rating')
            ->groupBy('products.id', 'products.name', 'products.image_url')
            ->orderBy('products.id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Products with review stats fetched successfully.',
            'data' => $products,
        ]);
    }

    public function productReviews($productId)
    {
        $product = Product::select('id', 'name', 'image_url')->find($productId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        $reviews = ProductReview::with([
                'customer:id,username,email,profile_url'
            ])
            ->select('id', 'product_id', 'customer_id', 'rating', 'comment', 'is_visible', 'created_at')
            ->where('product_id', $productId)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Product reviews fetched successfully.',
            'product' => $product,
            'reviews' => $reviews,
        ]);
    }

    public function toggleVisibility($id)
    {
        $review = ProductReview::find($id);

        if (!$review) {
            return response()->json([
                'success' => false,
                'message' => 'Review not found.',
            ], 404);
        }

        $review->is_visible = !$review->is_visible;
        $review->save();

        return response()->json([
            'success' => true,
            'message' => $review->is_visible
                ? 'Review is now visible.'
                : 'Review has been hidden.',
            'data' => $review,
        ]);
    }

    public function customerProductReviews(Request $request, $productId)
    {
        $customer = $request->user();

        $product = Product::query()
            ->select('id', 'name', 'image_url', 'is_active')
            ->where('id', $productId)
            ->first();

        if (!$product || !$product->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        $myReview = ProductReview::with([
                'customer:id,username,email,profile_url'
            ])
            ->select('id', 'product_id', 'customer_id', 'rating', 'comment', 'is_visible', 'created_at', 'updated_at')
            ->where('product_id', $productId)
            ->where('customer_id', $customer->id)
            ->first();

        $otherReviews = ProductReview::with([
                'customer:id,username,email,profile_url'
            ])
            ->select('id', 'product_id', 'customer_id', 'rating', 'comment', 'is_visible', 'created_at', 'updated_at')
            ->where('product_id', $productId)
            ->where('is_visible', true)
            ->where('customer_id', '!=', $customer->id)
            ->latest()
            ->get();

        $summary = DB::table('product_reviews')
            ->selectRaw('COUNT(*) as review_count, COALESCE(AVG(rating), 0) as average_rating')
            ->where('product_id', $productId)
            ->where('is_visible', true)
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Customer product reviews fetched successfully.',
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'image_url' => $product->image_url,
            ],
            'summary' => [
                'review_count' => (int) ($summary->review_count ?? 0),
                'average_rating' => round((float) ($summary->average_rating ?? 0), 1),
            ],
            'my_review' => $myReview,
            'other_reviews' => $otherReviews,
        ]);
    }

    public function upsertCustomerReview(Request $request, $productId)
    {
        $customer = $request->user();

        $product = Product::query()
            ->where('id', $productId)
            ->where('is_active', true)
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $review = ProductReview::updateOrCreate(
            [
                'product_id' => $productId,
                'customer_id' => $customer->id,
            ],
            [
                'rating' => $validated['rating'],
                'comment' => trim($validated['comment'] ?? ''),
            ]
        );

        $review->load('customer:id,username,email,profile_url');

        return response()->json([
            'success' => true,
            'message' => $review->wasRecentlyCreated
                ? 'Review submitted successfully.'
                : 'Review updated successfully.',
            'review' => $review,
        ]);
    }
}