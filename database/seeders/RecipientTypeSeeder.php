<?php

namespace Database\Seeders;

use App\Models\RecipientType;
use Illuminate\Database\Seeder;

class RecipientTypeSeeder extends Seeder
{
    public function run(): void
    {
        $recipientTypes = [
            ['Baby', true],
            ['Kids', true],
            ['Teen', true],
            ['School Student', true],
            ['College Student', true],
            ['Adult', false],
            ['Senior', true],
            ['Pet', true],
            ['Couple', true],
        ];

        foreach ($recipientTypes as $sortOrder => [$name, $active]) {
            RecipientType::query()->updateOrCreate(
                ['slug' => str($name)->slug()->toString()],
                [
                    'name' => $name,
                    'sort_order' => $sortOrder + 1,
                    'is_active' => $active,
                ],
            );
        }
    }
}
