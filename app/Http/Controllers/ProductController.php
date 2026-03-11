<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class ProductController extends Controller
{
    public function index()
    {
        $rows = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->select(
                'p.*',
                'c.name as category_name'
            )
            ->orderBy('p.id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows
        ]);
    }

    public function store(Request $request)
    {
        $viewer = $request->user('admin');
        $role = strtolower($viewer->role ?? 'operator');

        if ($role === 'operator') {
            return response()->json([
                'success' => false,
                'message' => 'Operators cannot create products.'
            ], 403);
        }

        $request->validate([
            'category_id' => 'required|integer',
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'unit_label' => 'required|string|max:100',
            'description' => 'required|string',
            'discount_active' => 'required|in:0,1',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'image' => 'required|image|max:4096',
        ]);

        $discountActive = (int)$request->discount_active;
        $discountPercent = $discountActive ? (float)$request->discount_percent : null;

        // enforce percent when active
        if ($discountActive === 1 && ($discountPercent === null || $discountPercent <= 0)) {
            return response()->json([
                'success' => false,
                'message' => 'Discount percent is required when discount is active.'
            ], 422);
        }

        // upload to cloudinary
        $upload = Cloudinary::uploadApi()->upload(
            $request->file('image')->getRealPath(),
            ['folder' => 'laoreat/products']
        );

        $imageUrl = $upload['secure_url'] ?? null;
        $publicId = $upload['public_id'] ?? null;

        $id = DB::table('products')->insertGetId([
            'category_id' => (int)$request->category_id,
            'name' => $request->name,
            'price' => $request->price,
            'unit_label' => $request->unit_label,
            'image_url' => $imageUrl,
            'image_public_id' => $publicId,
            'description' => $request->description,
            'is_available' => 1,
            'is_active' => 1,
            'discount_active' => $discountActive,
            'discount_percent' => $discountPercent,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->select('p.*', 'c.name as category_name')
            ->where('p.id', $id)
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Product created',
            'data' => $product
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $viewer = $request->user('admin');
        $role = strtolower($viewer->role ?? 'operator');

        if ($role === 'operator') {
            return response()->json([
                'success' => false,
                'message' => 'Operators cannot update products.'
            ], 403);
        }

        $product = DB::table('products')->where('id', $id)->first();
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        $request->validate([
            'category_id' => 'required|integer',
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'unit_label' => 'required|string|max:100',
            'description' => 'required|string',
            'discount_active' => 'required|in:0,1',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'image' => 'nullable|image|max:4096',
        ]);

        $discountActive = (int)$request->discount_active;
        $hasPct = $request->filled('discount_percent');
        $discountPercent = $hasPct ? (float)$request->discount_percent : null;

        if ($discountActive === 1 && (!$hasPct || $discountPercent <= 0)) {
            return response()->json([
                'success' => false,
                'message' => 'Discount percent is required when discount is active.'
            ], 422);
        }

        $update = [
            'category_id' => (int)$request->category_id,
            'name' => $request->name,
            'price' => $request->price,
            'unit_label' => $request->unit_label,
            'description' => $request->description,
            'discount_active' => $discountActive,
            'updated_at' => now(),
        ];

        if ($hasPct) {
            $update['discount_percent'] = $discountPercent;
        }

        // image replace (optional)
        if ($request->hasFile('image')) {
            if (!empty($product->image_public_id)) {
                Cloudinary::uploadApi()->destroy($product->image_public_id);
            }

            $upload = Cloudinary::uploadApi()->upload(
                $request->file('image')->getRealPath(),
                ['folder' => 'laoreat/products']
            );

            $update['image_url'] = $upload['secure_url'] ?? null;
            $update['image_public_id'] = $upload['public_id'] ?? null;
        }

        DB::table('products')->where('id', $id)->update($update);

        return response()->json([
            'success' => true,
            'message' => 'Product updated'
        ]);
    }

    public function setStatus(Request $request, $id)
    {
        $viewer = $request->user('admin');
        $role = strtolower($viewer->role ?? 'operator');

        if ($role === 'operator') {
            return response()->json([
                'success' => false,
                'message' => 'Operators cannot enable/disable products.'
            ], 403);
        }

        $product = DB::table('products')->where('id', $id)->first();
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $request->validate(['is_active' => 'required|in:0,1']);

        DB::table('products')->where('id', $id)->update([
            'is_active' => (int)$request->is_active,
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => ((int)$request->is_active === 1) ? 'Product enabled' : 'Product disabled'
        ]);
    }

    public function setAvailability(Request $request, $id)
    {
        $product = DB::table('products')->where('id', $id)->first();
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $request->validate(['is_available' => 'required|in:0,1']);

        DB::table('products')->where('id', $id)->update([
            'is_available' => (int)$request->is_available,
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Availability updated'
        ]);
    }

    public function appIndex(Request $request)
    {
        $query = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->select(
                'p.id',
                'p.category_id',
                'p.name',
                'p.price',
                'p.unit_label',
                'p.image_url',
                'p.description',
                'p.is_available',
                'p.discount_active',
                'p.discount_percent',
                'c.name as category_name'
            )
            ->where('p.is_active', 1);

        if ($request->filled('category_id')) {
            $query->where('p.category_id', (int) $request->category_id);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where('p.name', 'like', '%' . $search . '%');
        }

        $products = $query
            ->orderBy('p.id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    public function show($id)
    {
        $product = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->select(
                'p.id',
                'p.category_id',
                'p.name',
                'p.price',
                'p.unit_label',
                'p.image_url',
                'p.image_public_id',
                'p.description',
                'p.is_available',
                'p.discount_active',
                'p.discount_percent',
                'c.name as category_name'
            )
            ->where('p.id', $id)
            ->where('p.is_active', 1)
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.'
            ], 404);
        }

        $reviewSummary = DB::table('product_reviews')
            ->selectRaw('COUNT(*) as review_count, COALESCE(AVG(rating), 0) as average_rating')
            ->where('product_id', $id)
            ->where('is_visible', 1)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $product->id,
                'category_id' => $product->category_id,
                'category_name' => $product->category_name,
                'name' => $product->name,
                'price' => $product->price,
                'unit_label' => $product->unit_label,
                'image_url' => $product->image_url,
                'image_public_id' => $product->image_public_id,
                'description' => $product->description,
                'is_available' => $product->is_available,
                'discount_active' => $product->discount_active,
                'discount_percent' => $product->discount_percent,
                'review_count' => (int) ($reviewSummary->review_count ?? 0),
                'average_rating' => round((float) ($reviewSummary->average_rating ?? 0), 1),
            ],
        ]);
    }

    public function popular()
    {
        $products = DB::table('products as p')
            ->join('order_items as oi', 'oi.product_id', '=', 'p.id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->select(
                'p.id',
                'p.category_id',
                'p.name',
                'p.price',
                'p.unit_label',
                'p.image_url',
                'p.description',
                'p.is_available',
                'p.discount_active',
                'p.discount_percent',
                'c.name as category_name',
                DB::raw('COALESCE(SUM(oi.quantity), 0) as total_sold')
            )
            ->where('p.is_active', 1)
            ->whereIn('o.status', ['completed', 'delivered'])
            ->groupBy(
                'p.id',
                'p.category_id',
                'p.name',
                'p.price',
                'p.unit_label',
                'p.image_url',
                'p.description',
                'p.is_available',
                'p.discount_active',
                'p.discount_percent',
                'c.name'
            )
            ->orderByDesc('total_sold')
            ->orderBy('p.id', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }
}