<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class CategoryController extends Controller
{
    public function index() {
        $cats = DB::table('categories')
            ->select('id', 'name', 'image_url', 'image_public_id', 'sort_order', 'is_active', 'created_at', 'updated_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $cats,
        ]);
    }

    public function store(Request $request)
    {
        $viewer = $request->user('admin');
        $role = strtolower($viewer->role ?? 'operator');

        if ($role === 'operator') {
            return response()->json([
                'success' => false,
                'message' => 'Operators cannot create categories.'
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:100|unique:categories,name',
            'sort_order' => 'required|integer|min:0',
            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $upload = Cloudinary::uploadApi()->upload(
            $request->file('image')->getRealPath(),
            ['folder' => 'laoreat/categories']
        );

        $imageUrl = $upload['secure_url'] ?? null;
        $publicId = $upload['public_id'] ?? null;

        $id = DB::table('categories')->insertGetId([
            'name' => $request->name,
            'image_url' => $imageUrl,
            'image_public_id' => $publicId,
            'sort_order' => (int)$request->sort_order,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $category = DB::table('categories')
            ->select(
                'id',
                'name',
                'image_url',
                'image_public_id',
                'sort_order',
                'is_active',
                'created_at',
                'updated_at'
            )
            ->where('id', $id)
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully.',
            'data' => $category,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $viewer = $request->user('admin');
        $role = strtolower($viewer->role ?? 'operator');

        if ($role === 'operator') {
            return response()->json([
                'success' => false,
                'message' => 'Operators cannot update categories.'
            ], 403);
        }

        $category = DB::table('categories')->where('id', $id)->first();

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found.'
            ], 404);
        }

        $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('categories', 'name')->ignore($id),
            ],
            'sort_order' => 'required|integer|min:0',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $update = [
            'name' => $request->name,
            'sort_order' => (int)$request->sort_order,
            'updated_at' => now(),
        ];

        if ($request->hasFile('image')) {
            if (!empty($category->image_public_id)) {
                try {
                    Cloudinary::uploadApi()->destroy($category->image_public_id);
                } catch (\Throwable $e) {
                    // ignore old image delete failure
                }
            }

            $upload = Cloudinary::uploadApi()->upload(
                $request->file('image')->getRealPath(),
                ['folder' => 'laoreat/categories']
            );

            $update['image_url'] = $upload['secure_url'] ?? null;
            $update['image_public_id'] = $upload['public_id'] ?? null;
        }

        DB::table('categories')->where('id', $id)->update($update);

        $updatedCategory = DB::table('categories')
            ->select(
                'id',
                'name',
                'image_url',
                'image_public_id',
                'sort_order',
                'is_active',
                'created_at',
                'updated_at'
            )
            ->where('id', $id)
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully.',
            'data' => $updatedCategory,
        ]);
    }

    public function setStatus(Request $request, $id)
    {
        $viewer = $request->user('admin');
        $role = strtolower($viewer->role ?? 'operator');

        if ($role === 'operator') {
            return response()->json([
                'success' => false,
                'message' => 'Operators cannot enable/disable categories.'
            ], 403);
        }

        $category = DB::table('categories')->where('id', $id)->first();

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found.'
            ], 404);
        }

        $request->validate([
            'is_active' => 'required|in:0,1',
        ]);

        DB::table('categories')->where('id', $id)->update([
            'is_active' => (int)$request->is_active,
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => ((int)$request->is_active === 1)
                ? 'Category enabled successfully.'
                : 'Category disabled successfully.'
        ]);
    }
}
