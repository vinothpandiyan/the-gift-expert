<?php

namespace Tests\Unit\Actions\CatalogCandidate;

use App\Actions\CatalogCandidate\CreateCatalogCandidateAction;
use App\Enums\CatalogCandidatePriority;
use App\Enums\CatalogCandidateSourceType;
use App\Enums\CatalogCandidateStatus;
use App\Models\CatalogCandidate;
use App\Models\User;
use App\Support\CatalogCandidateTitleFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateCatalogCandidateActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_discovered_manual_candidate_with_defaults(): void
    {
        $user = User::factory()->create();
        $discoveredAt = now()->subDay()->startOfSecond();

        $candidate = app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Portable Photo Printer',
            'source_type' => CatalogCandidateSourceType::Manual,
            'created_by_user_id' => $user->id,
            'discovered_at' => $discoveredAt,
        ]);

        $this->assertSame(CatalogCandidateStatus::Discovered, $candidate->status);
        $this->assertSame(CatalogCandidatePriority::Normal, $candidate->priority);
        $this->assertSame($user->id, $candidate->created_by_user_id);
        $this->assertTrue($discoveredAt->equalTo($candidate->discovered_at));
        $this->assertSame(
            CatalogCandidateTitleFingerprint::from('Portable Photo Printer'),
            $candidate->title_fingerprint,
        );
        $this->assertCount(0, $candidate->evidence);
    }

    public function test_it_defaults_discovered_at_when_omitted(): void
    {
        $candidate = app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Leather Wallet',
            'source_type' => 'manual',
        ]);

        $this->assertNotNull($candidate->discovered_at);
        $this->assertTrue($candidate->discovered_at->diffInSeconds(now()) < 5);
    }

    public function test_it_creates_explicit_originating_evidence_without_copying_source_url(): void
    {
        $candidate = app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Travel Mug',
            'source_type' => CatalogCandidateSourceType::Web,
            'source_url' => 'https://example.com/idea',
            'evidence' => [
                'source_type' => CatalogCandidateSourceType::Community,
                'source_url' => 'https://example.com/thread',
                'summary' => 'Mentioned in a gift thread.',
                'metadata' => ['thread_id' => 't-1'],
            ],
        ]);

        $this->assertSame('https://example.com/idea', $candidate->source_url);
        $this->assertCount(1, $candidate->evidence);
        $this->assertSame('https://example.com/thread', $candidate->evidence->first()->source_url);
        $this->assertSame(['thread_id' => 't-1'], $candidate->evidence->first()->metadata);
    }

    public function test_it_does_not_auto_copy_source_url_into_evidence(): void
    {
        $candidate = app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Desk Lamp',
            'source_type' => CatalogCandidateSourceType::Web,
            'source_url' => 'https://example.com/lamp',
        ]);

        $this->assertCount(0, $candidate->evidence);
    }

    public function test_it_rejects_forced_non_discovered_initial_status(): void
    {
        foreach ([
            CatalogCandidateStatus::UnderReview,
            CatalogCandidateStatus::Approved,
            CatalogCandidateStatus::Rejected,
        ] as $status) {
            try {
                app(CreateCatalogCandidateAction::class)->execute([
                    'title' => 'Forced Status '.$status->value,
                    'source_type' => CatalogCandidateSourceType::Manual,
                    'status' => $status,
                ]);
                $this->fail('Expected ValidationException for status '.$status->value);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }
        }

        $this->assertSame(0, CatalogCandidate::query()->count());
    }

    public function test_it_rejects_duplicate_external_reference_with_normalized_source_name(): void
    {
        app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'First Wallet',
            'source_type' => CatalogCandidateSourceType::Merchant,
            'source_name' => 'Amazon',
            'external_reference' => 'B0EXAMPLE',
        ]);

        try {
            app(CreateCatalogCandidateAction::class)->execute([
                'title' => 'Second Wallet',
                'source_type' => CatalogCandidateSourceType::Merchant,
                'source_name' => '  AMAZON  ',
                'external_reference' => 'B0EXAMPLE',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('external_reference', $exception->errors());
            $this->assertStringContainsString('#', $exception->errors()['external_reference'][0]);
        }

        $this->assertSame(1, CatalogCandidate::query()->count());
    }

    public function test_it_rejects_the_same_source_url_and_title_fingerprint(): void
    {
        app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Portable Photo Printer',
            'source_type' => CatalogCandidateSourceType::Web,
            'source_url' => 'https://example.com/roundup',
        ]);

        $this->expectException(ValidationException::class);

        app(CreateCatalogCandidateAction::class)->execute([
            'title' => ' portable   photo-printer ',
            'source_type' => CatalogCandidateSourceType::Web,
            'source_url' => 'https://example.com/roundup',
        ]);
    }

    public function test_it_allows_the_same_source_url_with_a_different_title(): void
    {
        app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Portable Photo Printer',
            'source_type' => CatalogCandidateSourceType::Web,
            'source_url' => 'https://example.com/roundup',
        ]);

        $second = app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Leather Wallet',
            'source_type' => CatalogCandidateSourceType::Web,
            'source_url' => 'https://example.com/roundup',
        ]);

        $this->assertSame(2, CatalogCandidate::query()->count());
        $this->assertSame('Leather Wallet', $second->title);
    }

    public function test_it_rejects_the_same_title_fingerprint_by_default(): void
    {
        app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Portable Photo Printer',
            'source_type' => CatalogCandidateSourceType::Manual,
        ]);

        $this->expectException(ValidationException::class);

        app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Portable Photo-Printer',
            'source_type' => CatalogCandidateSourceType::Editorial,
            'source_url' => 'https://example.com/other',
        ]);
    }

    public function test_it_allows_the_same_title_fingerprint_when_overridden(): void
    {
        $first = app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Portable Photo Printer',
            'source_type' => CatalogCandidateSourceType::Manual,
        ]);

        $second = app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Portable Photo-Printer',
            'source_type' => CatalogCandidateSourceType::Editorial,
            'source_url' => 'https://example.com/other',
        ], allowSimilarTitle: true);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->title_fingerprint, $second->title_fingerprint);
        $this->assertSame(CatalogCandidateStatus::Discovered, $first->fresh()->status);
    }

    public function test_a_soft_deleted_candidate_does_not_block_a_new_candidate(): void
    {
        $existing = app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Portable Photo Printer',
            'source_type' => CatalogCandidateSourceType::Merchant,
            'source_name' => 'Amazon',
            'source_url' => 'https://example.com/printer',
            'external_reference' => 'B0EXAMPLE',
        ]);

        $existing->delete();

        $replacement = app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Portable Photo Printer',
            'source_type' => CatalogCandidateSourceType::Merchant,
            'source_name' => 'Amazon',
            'source_url' => 'https://example.com/printer',
            'external_reference' => 'B0EXAMPLE',
        ]);

        $this->assertNotSame($existing->id, $replacement->id);
        $this->assertSoftDeleted('catalog_candidates', ['id' => $existing->id]);
    }

    public function test_invalid_evidence_does_not_leave_a_partial_candidate(): void
    {
        try {
            app(CreateCatalogCandidateAction::class)->execute([
                'title' => 'Broken Evidence Candidate',
                'source_type' => CatalogCandidateSourceType::Web,
                'evidence' => [
                    'summary' => 'Missing source type.',
                ],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_type', $exception->errors());
        }

        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertDatabaseCount('catalog_candidate_evidence', 0);
    }
}
