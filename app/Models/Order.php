<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'customer_id',
        'delivery_type_id',
        'order_number',
        'subtotal',
        'promo_code',
        'promo_discount_percent',
        'discount_amount',
        'delivery_fee',
        'total_amount',
        'status',
        'payment_method',
        'delivery_address',
        'delivery_lat',
        'delivery_lng',
        'scheduled_for',
        'note_for_rider',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'promo_discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'delivery_lat' => 'decimal:7',
        'delivery_lng' => 'decimal:7',
        'scheduled_for' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function deliveryType()
    {
        return $this->belongsTo(DeliveryType::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }
}