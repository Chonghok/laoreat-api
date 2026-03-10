<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerAuthController;

Route::get('/health', fn() => response()->json(['ok' => true]));

// Admin public routes
Route::post('/admin/login', [AdminAuthController::class, 'login']);

// Customer public routes
Route::post('/customer/register', [CustomerAuthController::class, 'register']);
Route::post('/customer/login', [CustomerAuthController::class, 'login']);
Route::post('/customer/phone-otp/send', [CustomerAuthController::class, 'sendPhoneOtp']);
Route::post('/customer/phone-otp/verify', [CustomerAuthController::class, 'verifyPhoneOtp']);

// Admin protected routes
Route::middleware('auth:admin')->group(function () {
    Route::get('/admin/me', [AdminAuthController::class, 'me']);
    Route::post('/admin/logout', [AdminAuthController::class, 'logout']);
    // Admins routes
    Route::post('/create-admin', [AdminController::class, 'store']);
    Route::get('/get-admins', [AdminController::class, 'index']);
    Route::post('/admins/{id}', [AdminController::class, 'update']);
    Route::patch('/admins/{id}/status', [AdminController::class, 'setStatus']);

    // Categories routes
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::post('/categories/{id}', [CategoryController::class, 'update']);
    Route::patch('/categories/{id}/status', [CategoryController::class, 'setStatus']);

    // Products routes
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::post('/products/{id}', [ProductController::class, 'update']);
    Route::patch('/products/{id}/status', [ProductController::class, 'setStatus']);
    Route::patch('/products/{id}/availability', [ProductController::class, 'setAvailability']);
});

// Customer protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/customer/me', [CustomerAuthController::class, 'me']);
    Route::post('/customer/logout', [CustomerAuthController::class, 'logout']);
});



