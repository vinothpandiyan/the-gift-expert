<?php

namespace Database\Seeders;

use App\Models\Occasion;
use Illuminate\Database\Seeder;

class OccasionSeeder extends Seeder
{
    public function run(): void
    {
        $occasions = [
            ['Birthday', 'birthday', true],
            ['Anniversary', 'anniversary', true],
            ['Wedding', 'wedding', true],
            ['Engagement', 'engagement', true],
            ['Housewarming', 'housewarming', true],
            ['Baby Shower', 'baby-shower', true],
            ['Farewell', 'farewell', true],
            ['Retirement', 'retirement', true],
            ['Festival', 'festival', false],
            ['Diwali', 'diwali', true],
            ['Christmas', 'christmas', true],
            ['Raksha Bandhan', 'raksha-bandhan', true],
            ['Pongal', 'pongal', true],
            ['Eid', 'eid', true],
            ['New Year', 'new-year', true],
            ['Graduation', 'graduation', true],
            ['New Job / Promotion', 'new-job-promotion', true],
            ['Bridal Shower', 'bridal-shower', true],
            ['Holi', 'holi', true],
            ['Ganesh Chaturthi', 'ganesh-chaturthi', true],
            ['Onam', 'onam', true],
            ["Valentine's Day", 'valentines-day', true],
            ["Mother's Day", 'mothers-day', true],
            ["Father's Day", 'fathers-day', true],
            ['Just Because', 'just-because', true],
            ['Get Well Soon', 'get-well-soon', true],
        ];

        foreach ($occasions as $sortOrder => [$name, $slug, $active]) {
            Occasion::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'sort_order' => $sortOrder + 1,
                    'is_active' => $active,
                ],
            );
        }
    }
}
