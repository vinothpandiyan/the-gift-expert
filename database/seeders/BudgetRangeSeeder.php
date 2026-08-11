<?php

namespace Database\Seeders;

use App\Models\BudgetRange;
use Illuminate\Database\Seeder;

class BudgetRangeSeeder extends Seeder
{
    public function run(): void
    {
        $ranges = [
            ['Under ₹500', 'under-500', null, '499.99'],
            ['₹500–₹1,000', '500-1000', '500.00', '1000.00'],
            ['₹1,000–₹2,500', '1000-2500', '1000.00', '2500.00'],
            ['₹2,500–₹5,000', '2500-5000', '2500.00', '5000.00'],
            ['₹5,000–₹10,000', '5000-10000', '5000.00', '10000.00'],
            ['₹10,000+', '10000-plus', '10000.00', null],
        ];

        foreach ($ranges as $sortOrder => [$name, $slug, $minAmount, $maxAmount]) {
            BudgetRange::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'min_amount' => $minAmount,
                    'max_amount' => $maxAmount,
                    'currency' => 'INR',
                    'sort_order' => $sortOrder + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}
