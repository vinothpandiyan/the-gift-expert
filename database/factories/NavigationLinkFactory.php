<?php

namespace Database\Factories;

use App\Enums\NavigationLinkType;
use App\Models\NavigationLink;
use App\Models\NavigationSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NavigationLink>
 */
class NavigationLinkFactory extends Factory
{
    protected $model = NavigationLink::class;

    public function definition(): array
    {
        return [
            'navigation_section_id' => NavigationSection::factory(),
            'label' => fake()->words(3, true),
            'link_type' => NavigationLinkType::Relationship,
            'sort_order' => 0,
            'is_active' => true,
            'opens_in_new_tab' => false,
        ];
    }
}
