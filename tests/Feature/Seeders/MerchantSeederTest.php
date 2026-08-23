<?php

namespace Tests\Feature\Seeders;

use App\CommercialSourcing\CommercialSourcingMerchants;
use App\Import\ProviderImagePolicy;
use App\Models\Merchant;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MerchantSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $commercialSlugs = [
        'amazon-in',
        'fnp',
        'flipkart',
        'myntra',
    ];

    public function test_database_seeder_creates_active_commercial_merchants(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ($this->commercialSlugs as $slug) {
            $merchant = Merchant::query()->where('slug', $slug)->first();

            $this->assertNotNull($merchant, "Missing merchant [{$slug}]");
            $this->assertTrue($merchant->is_active);
        }

        $this->assertFalse(
            (bool) Merchant::query()->where('slug', 'placeholder')->value('is_active'),
        );
    }

    public function test_seeded_merchants_have_commercial_sourcing_config_entries(): void
    {
        $this->seed(MerchantSeeder::class);

        foreach ($this->commercialSlugs as $slug) {
            $config = config('commercial_sourcing.merchants.'.$slug);

            $this->assertIsArray($config);
            $this->assertTrue($config['enabled'] ?? false);
            $this->assertContains('IN', $config['markets'] ?? []);
        }

        $amazon = config('commercial_sourcing.merchants.amazon-in');
        $this->assertTrue($amazon['search_enabled'] ?? false);
        $this->assertTrue($amazon['affiliate_enabled'] ?? false);

        foreach (['fnp', 'flipkart', 'myntra'] as $slug) {
            $config = config('commercial_sourcing.merchants.'.$slug);
            $this->assertFalse($config['search_enabled'] ?? true);
            $this->assertFalse($config['affiliate_enabled'] ?? true);
        }
    }

    public function test_seeded_merchants_use_valid_import_image_policies(): void
    {
        $this->seed(MerchantSeeder::class);

        $expectedNetworks = [
            'amazon-in' => 'amazon_associates',
            'fnp' => 'manual',
            'flipkart' => 'manual',
            'myntra' => 'manual',
        ];

        foreach ($expectedNetworks as $slug => $network) {
            $merchant = Merchant::query()->where('slug', $slug)->firstOrFail();

            $this->assertSame($network, $merchant->affiliate_network);
            $this->assertSame(
                $network,
                config('commercial_sourcing.merchants.'.$slug.'.image_policy_key'),
            );
            $this->assertIsArray(config('import.providers.'.$network.'.policy'));
            $this->assertFalse(ProviderImagePolicy::forKey($network)->allowsLocalAcquisition());
        }
    }

    public function test_amazon_domain_resolves_for_commercial_sourcing(): void
    {
        $this->seed(MerchantSeeder::class);

        $resolver = app(CommercialSourcingMerchants::class);

        $this->assertSame(
            'amazon-in',
            $resolver->resolveFromUrl('https://www.amazon.in/dp/B0ABCDEFGH', 'IN')?->slug,
        );
    }

    public function test_merchant_seeder_is_idempotent(): void
    {
        $this->seed(MerchantSeeder::class);

        $count = Merchant::query()->count();

        $this->seed(MerchantSeeder::class);

        $this->assertSame($count, Merchant::query()->count());
        $this->assertSame(5, $count);
    }

    public function test_placeholder_merchant_remains_available_for_product_seeder(): void
    {
        $this->seed([
            MerchantSeeder::class,
            ProductSeeder::class,
        ]);

        $this->assertSame(1, Merchant::query()->where('slug', 'placeholder')->count());
        $this->assertFalse(
            (bool) Merchant::query()->where('slug', 'placeholder')->value('is_active'),
        );
    }
}
