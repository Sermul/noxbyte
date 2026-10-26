<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StoreCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            'Gaming Mouse' => [
                ['Wireless Gaming Mouse', 'Logitech', 89.99],
            ],
            'Gaming Keyboards' => [
                ['Mechanical Gaming Keyboard', 'Keychron', 129.99],
            ],
            'Gaming Headsets' => [
                ['Surround Gaming Headset', 'HyperX', 79.99],
            ],
            'Mousepads' => [
                ['Extended Gaming Mousepad', 'SteelSeries', 29.99],
            ],
            'Controllers' => [
                ['Wireless Game Controller', '8BitDo', 59.99],
            ],
            'Gaming Chairs' => [
                ['Ergonomic Gaming Chair', 'Razer', 299.99],
            ],
            'Gaming Accessories' => [
                ['USB Microphone', 'Corsair', 99.99],
            ],
        ];

        foreach ($catalog as $categoryName => $products) {
            $categorySlug = Str::slug($categoryName);
            $category = Category::firstOrCreate(
                ['slug' => $categorySlug],
                ['name' => $categoryName]
            );

            foreach ($products as [$name, $brand, $price]) {
                Product::firstOrCreate(
                    ['slug' => Str::slug($name)],
                    [
                        'category_id' => $category->id,
                        'name' => $name,
                        'brand' => $brand,
                        'price' => $price,
                        'stock' => 10,
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
