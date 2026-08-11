<?php

namespace Database\Seeders;

use App\Models\Occasion;
use Illuminate\Database\Seeder;

class OccasionSeeder extends Seeder
{
    public function run(): void
    {
        $occasions = [
            'Birthday',
            'Anniversary',
            'Wedding',
            'Engagement',
            'Housewarming',
            'Baby Shower',
            'Farewell',
            'Retirement',
            'Festival',
            'Diwali',
            'Christmas',
            'Raksha Bandhan',
            'Pongal',
            'Eid',
            'New Year',
        ];

        foreach ($occasions as $sortOrder => $name) {
            Occasion::query()->updateOrCreate(
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
