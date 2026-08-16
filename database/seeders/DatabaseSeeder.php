<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            MerchantSeeder::class,
            BudgetRangeSeeder::class,
            OccasionSeeder::class,
            RelationshipSeeder::class,
            RecipientTypeSeeder::class,
            InterestSeeder::class,
            ProfessionSeeder::class,
            GiftTypeSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
            SeoLandingPageSeeder::class,
            NavigationSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
