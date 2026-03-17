<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromoCode extends Model
{
    protected $fillable = [
        'code',
        'discount_percent',
        'min_amount',
        'first_order_only',
        'min_completed_orders',
        'max_usage_per_customer',
        'max_total_usage',
        'used_count',
        'description',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'discount_percent' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'first_order_only' => 'boolean',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];
}