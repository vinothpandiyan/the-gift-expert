<?php

namespace Database\Seeders;

use App\Models\Merchant;
use Illuminate\Database\Seeder;

class MerchantSeeder extends Seeder
{
    public function run(): void
    {
        $merchants = [
            [
                'slug' => 'amazon-in',
                'name' => 'Amazon India',
                'affiliate_network' => 'amazon_associates',
                'website_url' => 'https://www.amazon.in/',
                'is_active' => true,
            ],
            [
                'slug' => 'fnp',
                'name' => 'FNP',
                'affiliate_network' => 'manual',
                'website_url' => 'https://www.fnp.com/',
                'is_active' => true,
            ],
            [
                'slug' => 'flipkart',
                'name' => 'Flipkart',
                'affiliate_network' => 'manual',
                'website_url' => 'https://www.flipkart.com/',
                'is_active' => true,
            ],
            [
                'slug' => 'myntra',
                'name' => 'Myntra',
                'affiliate_network' => 'manual',
                'website_url' => 'https://www.myntra.com/',
                'is_active' => true,
            ],
        ];

        foreach ($merchants as $merchant) {
            Merchant::query()->updateOrCreate(
                ['slug' => $merchant['slug']],
                [
                    'name' => $merchant['name'],
                    'affiliate_network' => $merchant['affiliate_network'],
                    'website_url' => $merchant['website_url'],
                    'is_active' => $merchant['is_active'],
                ],
            );
        }

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
