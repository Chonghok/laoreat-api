<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Khmer Food',
                'sort_order' => 1,
                'image_url' => 'https://res.cloudinary.com/ddtls0ctx/image/upload/v1772721056/laoreat/categories/khmer-food_y5cw44.jpg',
                'image_public_id' => 'laoreat/categories/khmer-food_y5cw44',
            ],
            [
                'name' => 'Western Food',
                'sort_order' => 2,
                'image_url' => 'https://res.cloudinary.com/ddtls0ctx/image/upload/v1772721870/laoreat/categories/western-food_dubm2a.jpg',
                'image_public_id' => 'laoreat/categories/western-food_dubm2a',
            ],
            [
                'name' => 'Healthy',
                'sort_order' => 3,
                'image_url' => 'https://res.cloudinary.com/ddtls0ctx/image/upload/v1772721898/laoreat/categories/healthy_dbhtoi.jpg',
                'image_public_id' => 'laoreat/categories/healthy_dbhtoi',
            ],
            [
                'name' => 'Dessert', 
                'sort_order' => 4,
                'image_url' => 'https://res.cloudinary.com/ddtls0ctx/image/upload/v1772721909/laoreat/categories/dessert_houjcu.jpg',
                'image_public_id' => 'laoreat/categories/dessert_houjcu',
            ],
            [
                'name' => 'Coffee & Tea', 
                'sort_order' => 5,
                'image_url' => 'https://res.cloudinary.com/ddtls0ctx/image/upload/v1772721923/laoreat/categories/coffee-tea_dbrimx.jpg',
                'image_public_id' => 'laoreat/categories/coffee-tea_dbrimx',
            ],
            [
                'name' => 'Soft Drink', 
                'sort_order' => 6,
                'image_url' => 'https://res.cloudinary.com/ddtls0ctx/image/upload/v1772721934/laoreat/categories/soft-drink_oqzy62.jpg',
                'image_public_id' => 'laoreat/categories/soft-drink_oqzy62',
            ],
        ]; 

        foreach ($categories as $c) {
            DB::table('categories')->updateOrInsert(
                ['name' => $c['name']], 
                [
                    'image_url' => $c['image_url'],
                    'image_public_id' => $c['image_public_id'],
                    'sort_order' => $c['sort_order'],
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
