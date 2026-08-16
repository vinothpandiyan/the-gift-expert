<?php

namespace Database\Factories;

use App\Enums\NavigationItemType;
use App\Models\NavigationMenu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NavigationMenu>
 */
class NavigationMenuFactory extends Factory
{
    protected $model = NavigationMenu::class;

    public function definition(): array
    {
        $label = fake()->unique()->words(2, true);

        return [
            'label' => $label,
            'slug' => str($label)->slug()->toString(),
            'item_type' => NavigationItemType::Mega,
            'sort_order' => 0,
            'is_active' => true,
            'opens_in_new_tab' => false,
        ];
    }

    public function mega(): static
    {
        return $this->state(fn (array $attributes) => [
            'item_type' => NavigationItemType::Mega,
        ]);
    }

    public function link(): static
    {
        return $this->state(fn (array $attributes) => [
            'item_type' => NavigationItemType::Link,
        ]);
    }
}
