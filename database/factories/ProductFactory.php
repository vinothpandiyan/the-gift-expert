<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'status' => ProductStatus::Draft,
            'price_amount' => fake()->randomFloat(2, 500, 10000),
            'price_currency' => 'INR',
            'is_featured' => false,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductStatus::Draft,
            'published_at' => null,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductStatus::Published,
            'published_at' => now(),
        ]);
    }
}
