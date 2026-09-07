<?php

namespace Database\Seeders;

use App\Models\Interest;
use Illuminate\Database\Seeder;

class InterestSeeder extends Seeder
{
    public function run(): void
    {
        $interests = [
            ['Home Chef / Foodie', 'food', null],
            ['Coffee & Tea', 'coffee', 'Coffee & Tea Enthusiast'],
            ['Fitness', 'fitness', null],
            ['Travel', 'travel', null],
            ['Tech & Gadgets', 'technology', 'Tech Lover / Gadget Geek'],
            ['Book Lover', 'books', null],
            ['Music Lover', 'music', 'Music Lover / Instrumentalist'],
            ['Pet Parent', 'pets', null],
            ['Photography', 'photography', null],
            ['Eco-Conscious', 'eco-friendly', 'Eco-Conscious / Sustainable Living'],
            ['Gaming', 'gaming', null],
            ['Self-Care & Wellness', 'self-care-wellness', null],
            ['Gardening', 'gardening', 'Gardening / Plant Parent'],
            ['Art & Crafts', 'art-and-crafts', null],
            ['WFH / Desk Setup', 'wfh-desk-setup', null],
            ['Spirituality', 'spirituality', null],
            ['Sports Fan', 'sports-fan', null],
        ];

        foreach ($interests as $sortOrder => [$name, $slug, $description]) {
            Interest::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'description' => $description,
                    'sort_order' => $sortOrder + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}
