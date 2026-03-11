<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = [
        'name',
        'image_url',
        'image_public_id',
        'sort_order',
        'is_active',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}