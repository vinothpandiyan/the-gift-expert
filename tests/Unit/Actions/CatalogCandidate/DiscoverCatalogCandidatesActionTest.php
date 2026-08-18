<?php

namespace Tests\Unit\Actions\CatalogCandidate;

use App\Actions\CatalogCandidate\CreateCatalogCandidateAction;
use App\Actions\CatalogCandidate\DiscoverCatalogCandidatesAction;
use App\Actions\CatalogCandidate\GroundDiscoveredCandidatesAction;
use App\CatalogCandidate\Discovery\CatalogCandidateDiscoveryProvider;
use App\CatalogCandidate\Discovery\CatalogCandidateDiscoveryResult;
use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Discovery\FakeCatalogCandidateDiscoveryProvider;
use App\CatalogCandidate\Discovery\RetrievedCatalogCandidateSource;
use App\Enums\CatalogCandidateDiscoveryRunStatus;
use App\Enums\CatalogCandidateIngestionFormat;
use App\Enums\CatalogCandidateIngestionItemStatus;
use App\Enums\CatalogCandidateSourceType;
use App\Enums\CatalogCandidateStatus;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateDiscoveryRun;
use App\Models\CatalogCandidateEvidence;
use App\Models\CatalogCandidateIngestionItem;
use App\Models\CatalogCandidateIngestionRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class DiscoverCatalogCandidatesActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_discovery_ingests_grounded_candidates_through_existing_actions(): void
    {
        $result = app(DiscoverCatalogCandidatesAction::class)->execute(
            CatalogCandidateResearchBrief::from('Find useful birthday gift ideas for husbands in India'),
        );

        $this->assertSame(3, $result->itemsSucceeded);
        $this->assertSame(0, $result->itemsFailed);
        $this->assertSame(CatalogCandidateIngestionFormat::Discovery, $result->run->format);
        $this->assertSame('discovery:fake', $result->run->source_name);

        $discoveryRun = CatalogCandidateDiscoveryRun::query()->first();

        $this->assertNotNull($discoveryRun);
        $this->assertSame('fake', $discoveryRun->provider_key);
        $this->assertSame(CatalogCandidateDiscoveryRunStatus::Completed, $discoveryRun->status);
        $this->assertSame($result->run->id, $discoveryRun->catalog_candidate_ingestion_run_id);
        $this->assertSame(3, $discoveryRun->candidates_proposed);

        $candidates = CatalogCandidate::query()->orderBy('id')->get();

        $this->assertCount(3, $candidates);
        $this->assertSame(CatalogCandidateStatus::Discovered, $candidates[0]->status);
        $this->assertSame(CatalogCandidateSourceType::AiResearch, $candidates[0]->source_type);
        $this->assertSame('Portable Photo Printer', $candidates[0]->title);
        $this->assertGreaterThanOrEqual(1, $candidates[0]->evidence()->count());
        $this->assertSame(4, CatalogCandidateEvidence::query()->count());
    }

    public function test_existing_title_duplicates_are_skipped_by_ingestion(): void
    {
        app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Portable Photo Printer',
            'source_type' => CatalogCandidateSourceType::Manual,
        ]);

        $result = app(DiscoverCatalogCandidatesAction::class)->execute(
            CatalogCandidateResearchBrief::from('birthday gifts for husbands'),
        );

        $this->assertSame(2, $result->itemsSucceeded);
        $this->assertSame(1, $result->itemsSkipped);
        $this->assertSame(CatalogCandidateIngestionItemStatus::Skipped, $result->outcomes[0]->status);
        $this->assertSame(3, CatalogCandidate::query()->count());
    }

    public function test_html_evidence_summaries_fail_through_existing_ingestion_validation(): void
    {
        $this->app->instance(CatalogCandidateDiscoveryProvider::class, new class implements CatalogCandidateDiscoveryProvider
        {
            public function discover(CatalogCandidateResearchBrief $brief): CatalogCandidateDiscoveryResult
            {
                return new CatalogCandidateDiscoveryResult(
                    candidates: [[
                        'title' => 'Portable Photo Printer',
                        'source_type' => 'ai_research',
                        'evidence' => [[
                            'source_type' => 'web',
                            'source_url' => 'https://example.com/roundup',
                            'summary' => '<html><body>copied page</body></html>',
                        ]],
                    ]],
                    corpus: [
                        new RetrievedCatalogCandidateSource(
                            url: 'https://example.com/roundup',
                            title: 'Roundup',
                            snippet: 'Printers',
                            sourceName: 'example.com',
                            retrievedAt: now(),
                        ),
                    ],
                );
            }
        });

        config(['catalog_candidate_discovery.providers.html_stub' => [
            'class' => CatalogCandidateDiscoveryProvider::class,
            'allowed_environments' => ['local', 'testing'],
        ]]);
        config(['catalog_candidate_discovery.provider' => 'html_stub']);

        $result = app(DiscoverCatalogCandidatesAction::class)->execute(
            CatalogCandidateResearchBrief::from('gifts'),
        );

        $this->assertSame(0, $result->itemsSucceeded);
        $this->assertSame(1, $result->itemsFailed);
        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(
            'Evidence summaries must be concise notes, not full page HTML.',
            $result->outcomes[0]->error,
        );
    }

    public function test_ungrounded_candidates_are_recorded_as_failed_ingestion_items(): void
    {
        $this->app->instance(CatalogCandidateDiscoveryProvider::class, new class implements CatalogCandidateDiscoveryProvider
        {
            public function discover(CatalogCandidateResearchBrief $brief): CatalogCandidateDiscoveryResult
            {
                return new CatalogCandidateDiscoveryResult(
                    candidates: [[
                        'title' => 'Invented Gadget',
                        'source_type' => 'ai_research',
                        'evidence' => [[
                            'source_type' => 'web',
                            'source_url' => 'https://invented.example.com/nope',
                            'summary' => 'Not in the corpus.',
                        ]],
                    ]],
                    corpus: [
                        new RetrievedCatalogCandidateSource(
                            url: 'https://example.com/roundup',
                            title: 'Roundup',
                            snippet: 'Printers',
                            sourceName: 'example.com',
                            retrievedAt: now(),
                        ),
                    ],
                );
            }
        });

        config(['catalog_candidate_discovery.providers.ungrounded_stub' => [
            'class' => CatalogCandidateDiscoveryProvider::class,
            'allowed_environments' => ['local', 'testing'],
        ]]);
        config(['catalog_candidate_discovery.provider' => 'ungrounded_stub']);

        $result = app(DiscoverCatalogCandidatesAction::class)->execute(
            CatalogCandidateResearchBrief::from('gifts'),
        );

        $this->assertSame(1, $result->itemsFailed);
        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(1, CatalogCandidateIngestionItem::query()->count());
        $this->assertSame('Evidence URLs must match a retrieved source URL.', $result->outcomes[0]->error);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $result = app(DiscoverCatalogCandidatesAction::class)->execute(
            CatalogCandidateResearchBrief::from('birthday gifts for husbands'),
            dryRun: true,
        );

        $this->assertSame(3, $result->itemsSucceeded);
        $this->assertNull($result->run);
        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateEvidence::query()->count());
        $this->assertSame(0, CatalogCandidateDiscoveryRun::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionItem::query()->count());
    }

    public function test_fake_provider_is_rejected_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        try {
            app(DiscoverCatalogCandidatesAction::class)->execute(
                CatalogCandidateResearchBrief::from('gifts'),
            );
            $this->fail('Expected the fake provider to be rejected in production.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'The [fake] catalog candidate discovery provider is not permitted in this environment.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateDiscoveryRun::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
    }

    public function test_unknown_providers_fail_before_ingestion(): void
    {
        config(['catalog_candidate_discovery.provider' => 'missing']);

        try {
            app(DiscoverCatalogCandidatesAction::class)->execute(
                CatalogCandidateResearchBrief::from('gifts'),
            );
            $this->fail('Expected an unknown provider to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Unknown catalog candidate discovery provider [missing].', $exception->getMessage());
        }

        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
        $this->assertSame(0, CatalogCandidateDiscoveryRun::query()->count());
        $this->assertSame(0, CatalogCandidate::query()->count());
    }

    public function test_grounding_does_not_write_candidates(): void
    {
        $discovered = app(FakeCatalogCandidateDiscoveryProvider::class)
            ->discover(CatalogCandidateResearchBrief::from('gifts'));

        app(GroundDiscoveredCandidatesAction::class)->execute($discovered);

        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateEvidence::query()->count());
    }
}
