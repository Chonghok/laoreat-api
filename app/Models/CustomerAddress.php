<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    protected $fillable = [
        'customer_id',
        'label',
        'delivery_address',
        'delivery_lat',
        'delivery_lng',
        'is_active',
    ];

    protected $casts = [
        'delivery_lat' => 'decimal:7',
        'delivery_lng' => 'decimal:7',
        'is_active' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}