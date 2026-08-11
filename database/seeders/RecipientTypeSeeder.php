<?php

namespace Database\Seeders;

use App\Models\RecipientType;
use Illuminate\Database\Seeder;

class RecipientTypeSeeder extends Seeder
{
    public function run(): void
    {
        $recipientTypes = [
            'Kids',
            'Teen',
            'Adult',
            'Senior',
            'Pet',
            'Couple',
        ];

        foreach ($recipientTypes as $sortOrder => $name) {
            RecipientType::query()->updateOrCreate(
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
