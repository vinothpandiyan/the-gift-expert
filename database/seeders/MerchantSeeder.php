<?php

namespace Database\Seeders;

use App\Models\Merchant;
use Illuminate\Database\Seeder;

class MerchantSeeder extends Seeder
{
    public function run(): void
    {
        Merchant::query()->updateOrCreate(
            ['slug' => 'placeholder'],
            [
                'name' => 'Placeholder Merchant',
                'affiliate_network' => 'placeholder',
                'website_url' => null,
                'is_active' => false,
            ],
        );
    }
}
