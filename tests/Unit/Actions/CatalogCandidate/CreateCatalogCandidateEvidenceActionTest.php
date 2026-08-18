<?php

namespace Tests\Unit\Actions\CatalogCandidate;

use App\Actions\CatalogCandidate\CreateCatalogCandidateEvidenceAction;
use App\Enums\CatalogCandidateSourceType;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateCatalogCandidateEvidenceActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_evidence_with_defaults(): void
    {
        $candidate = CatalogCandidate::factory()->create();

        $evidence = app(CreateCatalogCandidateEvidenceAction::class)->execute($candidate, [
            'source_type' => CatalogCandidateSourceType::Web,
            'source_name' => ' Example Forum ',
            'source_url' => ' https://example.com/thread ',
            'summary' => 'Mentioned as a popular gift.',
            'metadata' => ['thread_id' => 'abc-123'],
        ]);

        $this->assertSame(CatalogCandidateSourceType::Web, $evidence->source_type);
        $this->assertSame('Example Forum', $evidence->source_name);
        $this->assertSame('https://example.com/thread', $evidence->source_url);
        $this->assertNotNull($evidence->observed_at);
        $this->assertSame(['thread_id' => 'abc-123'], $evidence->fresh()->metadata);
        $this->assertTrue($evidence->candidate->is($candidate));
    }

    public function test_it_rejects_duplicate_source_urls_on_the_same_candidate(): void
    {
        $candidate = CatalogCandidate::factory()->create();

        app(CreateCatalogCandidateEvidenceAction::class)->execute($candidate, [
            'source_type' => CatalogCandidateSourceType::Web,
            'source_url' => 'https://example.com/thread',
        ]);

        $this->expectException(ValidationException::class);

        app(CreateCatalogCandidateEvidenceAction::class)->execute($candidate, [
            'source_type' => CatalogCandidateSourceType::Community,
            'source_url' => 'https://example.com/thread',
        ]);
    }

    public function test_it_allows_the_same_source_url_on_a_different_candidate(): void
    {
        $first = CatalogCandidate::factory()->create();
        $second = CatalogCandidate::factory()->create();
        $url = 'https://example.com/roundup';

        app(CreateCatalogCandidateEvidenceAction::class)->execute($first, [
            'source_type' => CatalogCandidateSourceType::Web,
            'source_url' => $url,
        ]);

        $evidence = app(CreateCatalogCandidateEvidenceAction::class)->execute($second, [
            'source_type' => CatalogCandidateSourceType::Web,
            'source_url' => $url,
        ]);

        $this->assertSame($url, $evidence->source_url);
        $this->assertSame(2, CatalogCandidateEvidence::query()->count());
    }

    public function test_it_rejects_evidence_for_a_soft_deleted_candidate(): void
    {
        $candidate = CatalogCandidate::factory()->create();
        $candidate->delete();

        $this->expectException(ValidationException::class);

        app(CreateCatalogCandidateEvidenceAction::class)->execute($candidate, [
            'source_type' => CatalogCandidateSourceType::Web,
            'source_url' => 'https://example.com/thread',
        ]);
    }

    public function test_it_rejects_full_page_html_summaries(): void
    {
        $candidate = CatalogCandidate::factory()->create();

        $this->expectException(ValidationException::class);

        app(CreateCatalogCandidateEvidenceAction::class)->execute($candidate, [
            'source_type' => CatalogCandidateSourceType::Web,
            'summary' => '<html><body>Copied article</body></html>',
        ]);
    }
}
