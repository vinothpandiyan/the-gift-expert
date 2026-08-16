<?php

namespace Tests\Unit\Actions;

use App\Actions\SeoLandingPage\RecordSeoLandingPageSlugRedirectAction;
use App\Models\SeoLandingPage;
use App\Models\SeoLandingPageRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordSeoLandingPageSlugRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_slug_change_records_redirect(): void
    {
        $page = SeoLandingPage::factory()->create([
            'slug' => 'birthday-gifts-for-husband',
        ]);

        $page->update(['slug' => 'birthday-gifts-for-husbands']);

        $this->assertDatabaseHas('seo_landing_page_redirects', [
            'from_slug' => 'birthday-gifts-for-husband',
            'to_slug' => 'birthday-gifts-for-husbands',
            'seo_landing_page_id' => $page->id,
        ]);
    }

    public function test_redirect_chain_collapses_to_final_slug(): void
    {
        SeoLandingPageRedirect::query()->create([
            'from_slug' => 'slug-a',
            'to_slug' => 'slug-b',
        ]);

        app(RecordSeoLandingPageSlugRedirectAction::class)->execute('slug-b', 'slug-c');

        $this->assertDatabaseHas('seo_landing_page_redirects', [
            'from_slug' => 'slug-a',
            'to_slug' => 'slug-c',
        ]);

        $this->assertDatabaseHas('seo_landing_page_redirects', [
            'from_slug' => 'slug-b',
            'to_slug' => 'slug-c',
        ]);
    }
}
