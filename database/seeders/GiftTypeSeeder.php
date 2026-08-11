<?php

namespace Database\Seeders;

use App\Models\GiftType;
use Illuminate\Database\Seeder;

class GiftTypeSeeder extends Seeder
{
    public function run(): void
    {
        $giftTypes = [
            ['Return Gifts', 'return-gifts'],
            ['Digital / Instant Gifts', 'digital-instant-gifts'],
            ['Gift Cards', 'gift-cards'],
            ['Subscriptions', 'subscriptions'],
            ['Online Courses', 'online-courses'],
            ['E-books & Audiobooks', 'ebooks-audiobooks'],
        ];

        foreach ($giftTypes as $sortOrder => [$name, $slug]) {
            GiftType::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'sort_order' => $sortOrder + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}
