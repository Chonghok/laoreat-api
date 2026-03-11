<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class Customer extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'username',
        'email',
        'password',
        'phone_number',
        'phone_verified_at',
        'profile_url',
        'profile_public_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'phone_verified_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function cart()
    {
        return $this->hasOne(Cart::class);
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }
}
