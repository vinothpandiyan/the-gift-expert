<?php

namespace Tests\Unit\Actions\CatalogCandidate;

use App\Actions\CatalogCandidate\ApproveCatalogCandidateAction;
use App\Actions\CatalogCandidate\RejectCatalogCandidateAction;
use App\Actions\CatalogCandidate\ReopenCatalogCandidateAction;
use App\Actions\CatalogCandidate\StartReviewCatalogCandidateAction;
use App\Enums\CatalogCandidateStatus;
use App\Models\CatalogCandidate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogCandidateReviewActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovered_can_move_to_under_review_without_final_review_stamp(): void
    {
        $reviewer = User::factory()->create();
        $candidate = CatalogCandidate::factory()->create();

        $updated = app(StartReviewCatalogCandidateAction::class)->execute($candidate, $reviewer);

        $this->assertSame(CatalogCandidateStatus::UnderReview, $updated->status);
        $this->assertNotNull($updated->last_evaluated_at);
        $this->assertNull($updated->reviewed_at);
        $this->assertSame($reviewer->id, $updated->reviewed_by_user_id);
    }

    public function test_discovered_can_be_rejected(): void
    {
        $reviewer = User::factory()->create();
        $candidate = CatalogCandidate::factory()->create();

        $updated = app(RejectCatalogCandidateAction::class)->execute($candidate, $reviewer);

        $this->assertSame(CatalogCandidateStatus::Rejected, $updated->status);
        $this->assertNotNull($updated->reviewed_at);
        $this->assertSame($reviewer->id, $updated->reviewed_by_user_id);
    }

    public function test_under_review_can_be_approved(): void
    {
        $reviewer = User::factory()->create();
        $candidate = CatalogCandidate::factory()->create([
            'status' => CatalogCandidateStatus::UnderReview,
        ]);

        $updated = app(ApproveCatalogCandidateAction::class)->execute($candidate, $reviewer);

        $this->assertSame(CatalogCandidateStatus::Approved, $updated->status);
        $this->assertNotNull($updated->reviewed_at);
        $this->assertNotNull($updated->last_evaluated_at);
        $this->assertTrue($updated->reviewed_at->equalTo($updated->last_evaluated_at));
        $this->assertSame($reviewer->id, $updated->reviewed_by_user_id);
    }

    public function test_under_review_can_be_rejected(): void
    {
        $candidate = CatalogCandidate::factory()->create([
            'status' => CatalogCandidateStatus::UnderReview,
        ]);

        $updated = app(RejectCatalogCandidateAction::class)->execute($candidate, User::factory()->create());

        $this->assertSame(CatalogCandidateStatus::Rejected, $updated->status);
        $this->assertNotNull($updated->reviewed_at);
    }

    public function test_approved_can_be_rejected(): void
    {
        $candidate = CatalogCandidate::factory()->create([
            'status' => CatalogCandidateStatus::Approved,
            'reviewed_at' => now()->subHour(),
        ]);

        $updated = app(RejectCatalogCandidateAction::class)->execute($candidate, User::factory()->create());

        $this->assertSame(CatalogCandidateStatus::Rejected, $updated->status);
        $this->assertNotNull($updated->reviewed_at);
    }

    public function test_rejected_can_be_reopened_and_clears_reviewed_at(): void
    {
        $previous = User::factory()->create();
        $current = User::factory()->create();
        $candidate = CatalogCandidate::factory()->create([
            'status' => CatalogCandidateStatus::Rejected,
            'reviewed_at' => now()->subHour(),
            'reviewed_by_user_id' => $previous->id,
        ]);

        $updated = app(ReopenCatalogCandidateAction::class)->execute($candidate, $current);

        $this->assertSame(CatalogCandidateStatus::UnderReview, $updated->status);
        $this->assertNull($updated->reviewed_at);
        $this->assertNotNull($updated->last_evaluated_at);
        $this->assertSame($current->id, $updated->reviewed_by_user_id);
    }

    public function test_invalid_transitions_are_rejected_without_changing_state(): void
    {
        $discovered = CatalogCandidate::factory()->create();
        $approved = CatalogCandidate::factory()->create([
            'status' => CatalogCandidateStatus::Approved,
            'reviewed_at' => now()->subHour(),
        ]);
        $rejected = CatalogCandidate::factory()->create([
            'status' => CatalogCandidateStatus::Rejected,
            'reviewed_at' => now()->subHour(),
        ]);

        $this->assertInvalidTransition(
            fn () => app(ApproveCatalogCandidateAction::class)->execute($discovered),
        );
        $this->assertSame(CatalogCandidateStatus::Discovered, $discovered->fresh()->status);
        $this->assertNull($discovered->fresh()->reviewed_at);

        $this->assertInvalidTransition(
            fn () => app(StartReviewCatalogCandidateAction::class)->execute($approved),
        );
        $this->assertSame(CatalogCandidateStatus::Approved, $approved->fresh()->status);

        $this->assertInvalidTransition(
            fn () => app(ApproveCatalogCandidateAction::class)->execute($approved),
        );
        $this->assertSame(CatalogCandidateStatus::Approved, $approved->fresh()->status);
        $this->assertNotNull($approved->fresh()->reviewed_at);

        $this->assertInvalidTransition(
            fn () => app(RejectCatalogCandidateAction::class)->execute($rejected),
        );
        $this->assertSame(CatalogCandidateStatus::Rejected, $rejected->fresh()->status);

        $this->assertInvalidTransition(
            fn () => app(ReopenCatalogCandidateAction::class)->execute($discovered),
        );
        $this->assertSame(CatalogCandidateStatus::Discovered, $discovered->fresh()->status);
    }

    public function test_repeated_approve_does_not_silently_succeed(): void
    {
        $candidate = CatalogCandidate::factory()->create([
            'status' => CatalogCandidateStatus::UnderReview,
        ]);

        app(ApproveCatalogCandidateAction::class)->execute($candidate, User::factory()->create());

        $this->assertInvalidTransition(
            fn () => app(ApproveCatalogCandidateAction::class)->execute($candidate->fresh()),
        );

        $this->assertSame(CatalogCandidateStatus::Approved, $candidate->fresh()->status);
    }

    private function assertInvalidTransition(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected ValidationException for invalid transition');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
    }
}
