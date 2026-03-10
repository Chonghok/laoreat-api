<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        // ✅ operator is allowed here
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
}