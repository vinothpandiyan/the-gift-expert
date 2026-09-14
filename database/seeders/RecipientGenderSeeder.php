<?php

namespace Database\Seeders;

use App\Models\RecipientGender;
use Illuminate\Database\Seeder;

class RecipientGenderSeeder extends Seeder
{
    public function run(): void
    {
        $genders = [
            [
                'name' => 'Male',
                'slug' => RecipientGender::SLUG_MALE,
                'description' => 'Products suitable for male recipients (men, boys, baby boy). UI labels may vary by context.',
                'is_active' => true,
            ],
            [
                'name' => 'Female',
                'slug' => RecipientGender::SLUG_FEMALE,
                'description' => 'Products suitable for female recipients (women, girls, baby girl). UI labels may vary by context.',
                'is_active' => true,
            ],
            [
                'name' => 'Unisex',
                'slug' => RecipientGender::SLUG_UNISEX,
                'description' => 'Products suitable for any recipient gender (Anyone).',
                'is_active' => true,
            ],
        ];

        foreach ($genders as $sortOrder => $gender) {
            RecipientGender::query()->updateOrCreate(
                ['slug' => $gender['slug']],
                [
                    'name' => $gender['name'],
                    'description' => $gender['description'],
                    'sort_order' => $sortOrder + 1,
                    'is_active' => $gender['is_active'],
                ],
            );
        }
    }
}
