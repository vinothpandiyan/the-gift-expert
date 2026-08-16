<?php

namespace Database\Factories;

use App\Enums\SeoLandingPageStatus;
use App\Models\SeoLandingPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeoLandingPage>
 */
class SeoLandingPageFactory extends Factory
{
    protected $model = SeoLandingPage::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(4, true);

        return [
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'heading' => $name,
            'status' => SeoLandingPageStatus::Draft,
            'is_indexable' => false,
            'include_in_sitemap' => false,
            'sort_order' => 0,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeoLandingPageStatus::Draft,
            'published_at' => null,
            'is_indexable' => false,
            'include_in_sitemap' => false,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeoLandingPageStatus::Published,
            'published_at' => now(),
        ]);
    }
}
