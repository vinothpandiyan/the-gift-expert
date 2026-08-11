<?php

namespace Tests\Feature\Discovery;

use App\Models\Occasion;
use App\Models\Product;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxonomyCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_taxonomy_page_resolves_and_excludes_unpublished_gifts(): void
    {
        $occasion = Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'description' => 'Celebrate another year',
            'is_active' => true,
        ]);

        $published = GiftCatalogTestHelpers::publishedGift([
            'name' => 'Birthday Candle',
            'slug' => 'birthday-candle',
        ]);
        $draft = Product::factory()->draft()->create([
            'name' => 'Secret Draft',
            'slug' => 'secret-draft',
        ]);

        $occasion->products()->attach($published->id);
        $occasion->products()->attach($draft->id);

        $this->get(DiscoveryUrl::occasion('birthday'))
            ->assertOk()
            ->assertSee('Birthday', false)
            ->assertSee('Celebrate another year', false)
            ->assertSee('Birthday Candle', false)
            ->assertDontSee('Secret Draft', false);
    }

    public function test_inactive_taxonomy_returns_not_found(): void
    {
        Occasion::query()->create([
            'name' => 'Hidden Occasion',
            'slug' => 'hidden-occasion',
            'is_active' => false,
        ]);

        $this->get(DiscoveryUrl::occasion('hidden-occasion'))->assertNotFound();
    }
}
