<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\PromoCodeController;
use App\Http\Controllers\DeliveryTypeController;
use App\Http\Controllers\ProductReviewController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\PaymentController;

Route::get('/health', fn() => response()->json(['ok' => true]));

// Admin public routes
Route::post('/admin/login', [AdminAuthController::class, 'login']);

// Customer public routes
Route::post('/customer/register', [CustomerAuthController::class, 'register']);
Route::post('/customer/login', [CustomerAuthController::class, 'login']);
Route::post('/customer/phone-otp/send', [CustomerAuthController::class, 'sendPhoneOtp']);
Route::post('/customer/phone-otp/verify', [CustomerAuthController::class, 'verifyPhoneOtp']);
Route::post('/customer/check-register-availability', [CustomerAuthController::class, 'checkRegisterAvailability']);
Route::post('/customer/password-reset/send-otp', [CustomerAuthController::class, 'sendResetPasswordOtp']);
Route::post('/customer/password-reset/verify-otp', [CustomerAuthController::class, 'verifyResetPasswordOtp']);
Route::post('/customer/password-reset/reset', [CustomerAuthController::class, 'resetPassword']);

// App public fetch routes
Route::get('/app/categories', [CategoryController::class, 'appIndex']);
Route::get('/app/products', [ProductController::class, 'appIndex']);
Route::get('/app/products/deals', [ProductController::class, 'deals']);
Route::get('/app/products/popular', [ProductController::class, 'popular']);
Route::get('/app/products/{id}', [ProductController::class, 'show']);

// Admin protected routes
Route::middleware('auth:admin')->group(function () {
    Route::get('/admin/me', [AdminAuthController::class, 'me']);
    Route::post('/admin/logout', [AdminAuthController::class, 'logout']);
    Route::get('/admin/dashboard', [DashboardController::class, 'overview']);
    
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

    // Product reviews routes
    Route::get('/products/reviews', [ProductReviewController::class, 'productsWithReviewStats']);
    Route::get('/products/{productId}/reviews', [ProductReviewController::class, 'productReviews']);
    Route::patch('/reviews/{id}/visibility', [ProductReviewController::class, 'toggleVisibility']);

    // Promotions routes
    Route::get('/promotions', [PromoCodeController::class, 'index']);
    Route::get('/promotions/{id}', [PromoCodeController::class, 'show']);
    Route::post('/promotions', [PromoCodeController::class, 'store']);
    Route::post('/promotions/{id}', [PromoCodeController::class, 'update']);
    Route::patch('/promotions/{id}/status', [PromoCodeController::class, 'setStatus']);

    // Delivery types routes
    Route::get('/delivery-types', [DeliveryTypeController::class, 'index']);
    Route::get('/delivery-types/{id}', [DeliveryTypeController::class, 'show']);
    Route::post('/delivery-types', [DeliveryTypeController::class, 'store']);
    Route::post('/delivery-types/{id}', [DeliveryTypeController::class, 'update']);
    Route::patch('/delivery-types/{id}/status', [DeliveryTypeController::class, 'setStatus']);

    // Customers routes
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::patch('/customers/{id}/status', [CustomerController::class, 'setStatus']);
    
    // Payments routes
    Route::get('/payments', [PaymentController::class, 'index']);

    // Orders routes
    Route::get('/admin/orders', [OrderController::class, 'adminOrders']);
    Route::patch('/admin/orders/{id}/status', [OrderController::class, 'updateAdminOrderStatus']);
});

// Customer protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/customer/me', [CustomerAuthController::class, 'me']);
    Route::post('/customer/logout', [CustomerAuthController::class, 'logout']);

    // Customer profile routes
    Route::post('/customer/profile', [CustomerAuthController::class, 'updateProfile']);
    Route::post('/customer/profile/phone/send-otp', [CustomerAuthController::class, 'sendProfilePhoneChangeOtp']);
    Route::post('/customer/profile/phone/verify', [CustomerAuthController::class, 'verifyProfilePhoneChangeOtp']);
    Route::post('/customer/change-password', [CustomerAuthController::class, 'changePassword']);
    Route::post('/customer/profile/photo', [CustomerAuthController::class, 'updateProfilePhoto']);
    Route::delete('/customer/profile/photo', [CustomerAuthController::class, 'removeProfilePhoto']);

    Route::get('/customer/promotions', [PromoCodeController::class, 'wallet']);
    
    // Customer favorites routes
    Route::get('/customer/favorites', [FavoriteController::class, 'index']);
    Route::post('/customer/favorites', [FavoriteController::class, 'store']);
    Route::delete('/customer/favorites/{productId}', [FavoriteController::class, 'destroy']);

    // Customer cart routes
    Route::get('/customer/cart', [CartController::class, 'show']);
    Route::post('/customer/cart/items', [CartController::class, 'addItem']);
    Route::patch('/customer/cart/items/{id}', [CartController::class, 'updateItem']);
    Route::delete('/customer/cart/items/{id}', [CartController::class, 'removeItem']);
    Route::delete('/customer/cart/clear', [CartController::class, 'clear']);

    // Customer orders routes
    Route::get('/customer/orders', [OrderController::class, 'customerOrders']);
    Route::get('/customer/orders/{id}', [OrderController::class, 'customerOrderDetail']);
    Route::get('/customer/delivery-types', [DeliveryTypeController::class, 'customerIndex']);
    Route::post('/customer/promotions/validate', [PromoCodeController::class, 'validateForCheckout']);
    Route::post('/customer/payments/stripe/intent', [OrderController::class, 'createStripeIntent']);
    Route::post('/customer/orders', [OrderController::class, 'store']);

    // Customer product reviews
    Route::get('/customer/products/{productId}/reviews', [ProductReviewController::class, 'customerProductReviews']);
    Route::post('/customer/products/{productId}/reviews', [ProductReviewController::class, 'upsertCustomerReview']);
});



