<?php

namespace Database\Seeders;

use App\Models\GiftType;
use App\Models\TaxonomySlugRedirect;
use Illuminate\Database\Seeder;

class GiftTypeSeeder extends Seeder
{
    public function run(): void
    {
        $giftTypes = [
            ['Return Gifts', 'return-gifts', true],
            ['Digital / Instant Gifts', 'digital-instant-gifts', true],
            ['Gift Cards', 'gift-cards', true],
            ['Subscriptions', 'subscriptions', true],
            ['Hampers / Gift Sets', 'hampers-gift-sets', true],
            ['Experience Gifts', 'experience-gifts', true],
            ['Personalized Gifts', 'personalized-gifts', true],
            ['Online Courses', 'online-courses', false],
            ['E-books & Audiobooks', 'ebooks-audiobooks', false],
        ];

        foreach ($giftTypes as $sortOrder => [$name, $slug, $active]) {
            GiftType::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'sort_order' => $sortOrder + 1,
                    'is_active' => $active,
                ],
            );
        }

        $this->seedSlugRedirect('online-courses', 'digital-instant-gifts');
        $this->seedSlugRedirect('ebooks-audiobooks', 'digital-instant-gifts');
    }

    private function seedSlugRedirect(string $fromSlug, string $toSlug): void
    {
        TaxonomySlugRedirect::query()->updateOrCreate(
            [
                'taxonomy' => 'gift_type',
                'from_slug' => $fromSlug,
            ],
            ['to_slug' => $toSlug],
        );
    }
}
