<?php

namespace Tests\Feature\RecipientEligibility;

use App\Actions\RecipientEligibility\EvaluateRecipientEligibilityReviewAction;
use App\RecipientEligibility\RecipientEligibilityConfig;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RecipientEligibilityReviewGateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/review-gate-'.uniqid());
        File::ensureDirectoryExists($this->dir);
        config([
            'recipient_eligibility.review_gate.required' => true,
            'recipient_eligibility.review_gate.file' => $this->dir.'/activation-gate.json',
            'recipient_eligibility.audit.min_reviewed' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_inference_flag_alone_cannot_activate_a_relationship_without_a_passing_review(): void
    {
        config(['recipient_eligibility.enabled' => true, 'recipient_eligibility.surfaces.relationship' => 'inferred', 'recipient_eligibility.relationships.husband.infer' => true]);

        $this->assertSame([], RecipientEligibilityConfig::inferringSlugs());
        $this->assertNull(RecipientEligibilityConfig::context('relationship'));
    }

    public function test_a_passing_review_activates_only_the_reviewed_relationship(): void
    {
        config(['recipient_eligibility.enabled' => true, 'recipient_eligibility.surfaces.relationship' => 'inferred']);
        config(['recipient_eligibility.relationships.husband.infer' => true, 'recipient_eligibility.relationships.wife.infer' => true]);

        $results = $this->evaluate(['husband' => ['keep' => 20], 'wife' => ['keep' => 18, 'reject' => 2]]);
        $this->writeGate($results);

        $this->assertTrue($results['husband']['passed']);
        $this->assertFalse($results['wife']['passed']);
        $this->assertSame(['husband'], RecipientEligibilityConfig::inferringSlugs());
    }

    public function test_threshold_is_ninety_five_percent_and_borderline_is_not_suitable(): void
    {
        $this->assertTrue($this->evaluate(['husband' => ['keep' => 19, 'reject' => 1]])['husband']['passed'], '95% passes');
        $this->assertFalse($this->evaluate(['husband' => ['keep' => 19, 'borderline' => 1, 'reject' => 1]])['husband']['passed']);
        $this->assertFalse($this->evaluate(['husband' => ['keep' => 18, 'borderline' => 2]])['husband']['passed'], 'borderline counts as not suitable');
    }

    public function test_unreviewed_rows_or_too_few_reviews_fail_the_gate(): void
    {
        $this->assertFalse($this->evaluate(['husband' => ['keep' => 20, 'unreviewed' => 1]])['husband']['passed']);
        $this->assertFalse($this->evaluate(['husband' => ['keep' => 10, 'unreviewed' => 10]])['husband']['passed']);
        $this->assertTrue($this->evaluate(['husband' => ['keep' => 5]], minReviewed: 30)['husband']['passed'], 'a small inferred pool only needs full review');
    }

    public function test_gate_can_be_disabled_for_local_experiments_only(): void
    {
        config(['recipient_eligibility.review_gate.required' => false]);

        $this->assertTrue(RecipientEligibilityConfig::reviewGateApproves('husband'));
    }

    public function test_review_file_requires_the_expected_columns(): void
    {
        $path = $this->dir.'/bad.csv';
        File::put($path, "foo,bar\n1,2\n");

        $this->expectException(\InvalidArgumentException::class);

        app(EvaluateRecipientEligibilityReviewAction::class)->execute($path);
    }

    /**
     * @param  array<string, array<string, int>>  $counts
     * @return array<string, array<string, mixed>>
     */
    private function evaluate(array $counts, ?int $minReviewed = null): array
    {
        if ($minReviewed !== null) {
            config(['recipient_eligibility.audit.min_reviewed' => $minReviewed]);
        }

        $rows = ['relationship,product_id,name,review'];
        $id = 1;

        foreach ($counts as $slug => $reviews) {
            foreach ($reviews as $review => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $rows[] = "{$slug},".$id++.',Gift,'.($review === 'unreviewed' ? '' : $review);
                }
            }
        }

        $path = $this->dir.'/review-'.uniqid().'.csv';
        File::put($path, implode("\n", $rows)."\n");

        return app(EvaluateRecipientEligibilityReviewAction::class)->execute($path);
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     */
    private function writeGate(array $results): void
    {
        File::put((string) config('recipient_eligibility.review_gate.file'), json_encode(['relationships' => $results]));
    }
}
