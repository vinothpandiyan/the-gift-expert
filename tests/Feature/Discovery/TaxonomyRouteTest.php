<?php

namespace Tests\Feature\Discovery;

use App\Models\Occasion;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxonomyRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_taxonomy_slug_resolves(): void
    {
        Occasion::query()->create([
            'name' => 'Birthday',
            'slug' => 'birthday',
            'is_active' => true,
        ]);

        $this->get(DiscoveryUrl::occasion('birthday'))
            ->assertOk()
            ->assertSee('Birthday', false);
    }

    public function test_inactive_taxonomy_slug_returns_not_found(): void
    {
        Occasion::query()->create([
            'name' => 'Hidden Occasion',
            'slug' => 'hidden-occasion',
            'is_active' => false,
        ]);

        $this->get(DiscoveryUrl::occasion('hidden-occasion'))
            ->assertNotFound();
    }
}
