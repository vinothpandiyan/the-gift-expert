<?php

namespace Tests\Unit\Actions\CatalogCandidate;

use App\Actions\CatalogCandidate\CreateCatalogCandidateAction;
use App\Actions\CatalogCandidate\UpdateCatalogCandidateAction;
use App\Enums\CatalogCandidateSourceType;
use App\Enums\CatalogCandidateStatus;
use App\Models\CatalogCandidate;
use App\Support\CatalogCandidateTitleFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UpdateCatalogCandidateActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_recomputes_the_title_fingerprint_and_preserves_status(): void
    {
        $candidate = app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Old Title',
            'source_type' => CatalogCandidateSourceType::Manual,
        ]);

        $updated = app(UpdateCatalogCandidateAction::class)->execute($candidate, [
            'title' => 'Portable Photo Printer',
            'source_type' => CatalogCandidateSourceType::Web,
            'summary' => 'Updated rationale.',
        ]);

        $this->assertSame('Portable Photo Printer', $updated->title);
        $this->assertSame(
            CatalogCandidateTitleFingerprint::from('Portable Photo Printer'),
            $updated->title_fingerprint,
        );
        $this->assertSame(CatalogCandidateStatus::Discovered, $updated->status);
        $this->assertSame('Updated rationale.', $updated->summary);
    }

    public function test_it_rejects_identity_duplicates_excluding_the_current_candidate(): void
    {
        app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Leather Wallet',
            'source_type' => CatalogCandidateSourceType::Merchant,
            'source_name' => 'Amazon',
            'external_reference' => 'B0EXAMPLE',
        ]);

        $candidate = app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Travel Mug',
            'source_type' => CatalogCandidateSourceType::Manual,
        ]);

        $this->expectException(ValidationException::class);

        app(UpdateCatalogCandidateAction::class)->execute($candidate, [
            'title' => 'Travel Mug',
            'source_type' => CatalogCandidateSourceType::Merchant,
            'source_name' => 'amazon',
            'external_reference' => 'B0EXAMPLE',
        ]);
    }

    public function test_it_ignores_status_in_the_payload(): void
    {
        $candidate = CatalogCandidate::factory()->create();

        $updated = app(UpdateCatalogCandidateAction::class)->execute($candidate, [
            'title' => $candidate->title,
            'source_type' => $candidate->source_type,
            'status' => CatalogCandidateStatus::Approved,
        ]);

        $this->assertSame(CatalogCandidateStatus::Discovered, $updated->status);
    }
}
