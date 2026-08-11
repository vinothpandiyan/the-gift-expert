<?php

namespace Database\Seeders;

use App\Models\Relationship;
use Illuminate\Database\Seeder;

class RelationshipSeeder extends Seeder
{
    public function run(): void
    {
        $relationships = [
            'Husband',
            'Boyfriend',
            'Father',
            'Brother',
            'Son',
            'Wife',
            'Girlfriend',
            'Mother',
            'Sister',
            'Daughter',
            'Friends',
            'Parents',
            'Colleagues',
            'Boss',
            'Newlyweds',
            'Grandparents',
        ];

        foreach ($relationships as $sortOrder => $name) {
            Relationship::query()->updateOrCreate(
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
