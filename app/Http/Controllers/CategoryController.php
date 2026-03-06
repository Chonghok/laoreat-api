<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index()
    {
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
}
