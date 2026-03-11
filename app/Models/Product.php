<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'price',
        'unit_label',
        'image_url',
        'image_public_id',
        'description',
        'is_available',
        'is_active',
        'discount_active',
        'discount_percent',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function favorites()
    {
        return $this->hasMany(Favorite::class);
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }
}