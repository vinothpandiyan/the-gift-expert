<?php

namespace Database\Seeders;

use App\Models\Interest;
use Illuminate\Database\Seeder;

class InterestSeeder extends Seeder
{
    public function run(): void
    {
        $interests = [
            'Food',
            'Coffee',
            'Fitness',
            'Travel',
            'Technology',
            'Books',
            'Music',
            'Pets',
            'Photography',
            'Eco-friendly',
        ];

        foreach ($interests as $sortOrder => $name) {
            Interest::query()->updateOrCreate(
                ['slug' => str($name)->slug()->toString()],
                [
                    'name' => $name,
                    'sort_order' => $sortOrder + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}
