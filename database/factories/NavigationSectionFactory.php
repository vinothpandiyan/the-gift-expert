<?php

namespace Database\Factories;

use App\Enums\NavigationSectionAppearance;
use App\Models\NavigationMenu;
use App\Models\NavigationSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NavigationSection>
 */
class NavigationSectionFactory extends Factory
{
    protected $model = NavigationSection::class;

    public function definition(): array
    {
        return [
            'navigation_menu_id' => NavigationMenu::factory(),
            'heading' => fake()->words(2, true),
            'appearance' => NavigationSectionAppearance::Default,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function cta(): static
    {
        return $this->state(fn (array $attributes) => [
            'appearance' => NavigationSectionAppearance::Cta,
        ]);
    }
}
