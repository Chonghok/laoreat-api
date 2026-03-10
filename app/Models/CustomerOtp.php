<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerOtp extends Model
{
    protected $table = 'customer_otps';

    protected $fillable = [
        'customer_id',
        'email',
        'phone_number',
        'code',
        'type',
        'expires_at',
        'verified_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];
}