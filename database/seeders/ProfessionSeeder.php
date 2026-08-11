<?php

namespace Database\Seeders;

use App\Models\Profession;
use Illuminate\Database\Seeder;

class ProfessionSeeder extends Seeder
{
    public function run(): void
    {
        $professions = [
            ['Doctor', 'doctor'],
            ['Teacher', 'teacher'],
            ['Engineer', 'engineer'],
            ['Software Developer', 'software-developer'],
            ['Business Owner', 'business-owner'],
            ['CA / Finance', 'ca-finance'],
            ['Designer', 'designer'],
            ['Content Creator', 'content-creator'],
        ];

        foreach ($professions as $sortOrder => [$name, $slug]) {
            Profession::query()->updateOrCreate(
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
