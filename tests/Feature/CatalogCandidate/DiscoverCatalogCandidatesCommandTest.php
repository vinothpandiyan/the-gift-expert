<?php

namespace Tests\Feature\CatalogCandidate;

use App\Enums\CatalogCandidateIngestionFormat;
use App\Enums\CatalogCandidateIngestionRunStatus;
use App\Models\AffiliateLink;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateEvidence;
use App\Models\CatalogCandidateIngestionItem;
use App\Models\CatalogCandidateIngestionRun;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscoverCatalogCandidatesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_it_discovers_candidates_through_the_fake_provider(): void
    {
        $this->artisan('catalog-candidates:discover', [
            'brief' => 'Find useful birthday gift ideas for husbands in India',
        ])
            ->expectsOutputToContain('Ingestion run')
            ->expectsOutputToContain('Succeeded: 3')
            ->expectsOutputToContain('Portable Photo Printer')
            ->assertSuccessful();

        $run = CatalogCandidateIngestionRun::query()->first();

        $this->assertNotNull($run);
        $this->assertSame(CatalogCandidateIngestionFormat::Discovery, $run->format);
        $this->assertSame('discovery:fake', $run->source_name);
        $this->assertSame(CatalogCandidateIngestionRunStatus::Completed, $run->status);
        $this->assertSame(3, CatalogCandidate::query()->count());
        $this->assertSame(4, CatalogCandidateEvidence::query()->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('catalog-candidates:discover', [
            'brief' => 'Find useful birthday gift ideas for husbands in India',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry run completed. No catalog candidates were written.')
            ->expectsOutputToContain('Succeeded: 3')
            ->expectsOutputToContain('Portable Photo Printer')
            ->assertSuccessful();

        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateEvidence::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionItem::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, ImportRun::query()->count());
    }

    public function test_a_blank_brief_fails(): void
    {
        $this->artisan('catalog-candidates:discover', [
            'brief' => '   ',
        ])
            ->expectsOutputToContain('A research brief is required.')
            ->assertFailed();

        $this->assertSame(0, CatalogCandidate::query()->count());
    }

    public function test_an_unknown_provider_fails_before_ingestion(): void
    {
        config(['catalog_candidate_discovery.provider' => 'missing']);

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'birthday gifts for husbands',
        ])
            ->expectsOutputToContain('Unknown catalog candidate discovery provider [missing].')
            ->assertFailed();

        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
    }

    public function test_fake_provider_is_rejected_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('catalog-candidates:discover', [
            'brief' => 'birthday gifts for husbands',
        ])
            ->expectsOutputToContain('The [fake] catalog candidate discovery provider is not permitted in this environment.')
            ->assertFailed();

        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, AffiliateLink::query()->count());
        $this->assertSame(0, ProductImage::query()->count());
        $this->assertSame(0, ImportRun::query()->count());
    }
}
