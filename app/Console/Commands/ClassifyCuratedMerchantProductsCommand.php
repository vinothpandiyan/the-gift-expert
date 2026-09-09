<?php

namespace App\Console\Commands;

use App\Actions\CuratedCatalog\ClassifyCuratedMerchantProductsBatchAction;
use App\Actions\CuratedCatalog\PlanCuratedMerchantProductClassificationAction;
use App\CuratedCatalog\CuratedClassificationBatchResult;
use App\CuratedCatalog\CuratedClassificationPlan;
use App\Models\CuratedProductIntakeRun;
use Illuminate\Console\Command;

class ClassifyCuratedMerchantProductsCommand extends Command
{
    protected $signature = 'catalog:classify-curated
        {--intake-run= : Limit to products from this curated intake run}
        {--product= : Classify a single product ID}
        {--merchant= : Limit to a merchant slug}
        {--status= : Current taxonomy classification status (default: none unless --intake-run is set)}
        {--limit= : Maximum products to consider}
        {--dry-run : Report eligible products without calling AI}
        {--force : Classify even when current or human-locked}
        {--retry-failed : Permit retry of failed classifications}
        {--queue : Dispatch per-product jobs instead of classifying in this process}';

    protected $description = 'Classify unique curated merchant products after provenance merge.';

    public function handle(
        PlanCuratedMerchantProductClassificationAction $planClassification,
        ClassifyCuratedMerchantProductsBatchAction $classifyBatch,
    ): int {
        $intakeRunId = $this->optionInteger('intake-run');
        $productId = $this->optionInteger('product');
        $status = $this->resolvedStatus($intakeRunId, $productId);
        $force = (bool) $this->option('force');
        $retryFailed = (bool) $this->option('retry-failed') || $force;
        $dryRun = (bool) $this->option('dry-run');
        $queue = (bool) $this->option('queue');

        if ($intakeRunId !== null && ! CuratedProductIntakeRun::query()->whereKey($intakeRunId)->exists()) {
            $this->error("Intake run [{$intakeRunId}] was not found.");

            return self::FAILURE;
        }

        if ($intakeRunId === null && $productId === null) {
            $this->warn('No --intake-run was provided. Matching products across the database will be considered.');
        }

        $plan = $planClassification->execute(
            $intakeRunId,
            $productId,
            $this->optionString('merchant'),
            $status,
            $this->optionInteger('limit'),
            $force,
            $retryFailed,
        );

        if ($dryRun) {
            $this->printPlan($plan);
            $this->comment('Dry run completed. No enrichment or classification writes were performed.');

            return self::SUCCESS;
        }

        $result = $classifyBatch->execute(
            $plan,
            $force,
            $retryFailed,
            $queue,
            $queue ? null : function (array $snapshot) use ($plan): void {
                $this->line(sprintf(
                    '[%d/%d] product=%d classified status=%s reason=%s remaining=%d',
                    $snapshot['processed'],
                    $plan->eligible,
                    $snapshot['product_id'],
                    $snapshot['status'],
                    $snapshot['reason'],
                    $snapshot['remaining'],
                ));
            },
        );

        $this->printResult($result, $plan, $queue);

        return self::SUCCESS;
    }

    private function resolvedStatus(?int $intakeRunId, ?int $productId = null): ?string
    {
        $status = $this->optionString('status');

        if ($status !== null) {
            return $status;
        }

        if ($intakeRunId !== null || $productId !== null) {
            return null;
        }

        return 'none';
    }

    private function printPlan(CuratedClassificationPlan $plan): void
    {
        foreach ($plan->items as $item) {
            if (! $item->eligible) {
                continue;
            }

            $this->line(sprintf(
                'product=%d status=%s version=%s reason=%s hints=%s',
                $item->productId,
                $item->status,
                $item->version ?? '—',
                $item->decisionReason,
                $item->hintSlugs === [] ? '—' : implode(',', $item->hintSlugs),
            ));
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Products considered', $plan->totalConsidered],
                ['Unique Products requiring AI classification', $plan->eligible],
                ['Products skipped because already current', $plan->skippedCurrent],
                ['Human locked', $plan->skippedLocked],
                ['Other skips', $plan->skippedOther],
                ['Products with trusted source hints', $plan->withTrustedHints],
                ['Products with no recipient hints', $plan->withoutRecipientHints],
                ['Failed that would retry', $plan->failedWouldRetry],
                ['Failed that would skip (use --retry-failed)', $plan->failedWouldSkip],
                ['Current classification version', $plan->classificationVersion],
                ['Estimated max output tokens', $plan->estimatedMaxOutputTokens],
            ],
        );

        $this->newLine();
        $this->comment('raw wishlist rows != AI calls. AI calls equal eligible unique Products.');

        if ($plan->intakeRunId !== null) {
            $this->comment("Scoped to intake run {$plan->intakeRunId}.");
        }
    }

    private function printResult(
        CuratedClassificationBatchResult $result,
        CuratedClassificationPlan $plan,
        bool $queued,
    ): void {
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total eligible', $result->totalEligible],
                ['Processed', $result->processed],
                ['AI Accepted', $result->aiAccepted],
                ['Review', $result->review],
                ['Failed', $result->failed],
                ['Skipped current', $result->skippedCurrent],
                ['Skipped locked', $result->skippedLocked],
                ['Remaining', $result->remaining],
                ['AI calls', $result->aiCalls],
                ['Queued jobs', $result->queued],
            ],
        );

        $this->newLine();
        $this->info("AI Accepted: {$result->aiAccepted}");
        $this->info("Needs Review: {$result->review}");
        $this->info("Failed: {$result->failed}");

        $this->newLine();
        $this->comment('Do not auto-approve REVIEW products. Do not auto-publish AI Accepted products.');
        $this->comment('Review queue (Admin → Catalog → Gifts):');
        $this->line('  Failed: /admin/gifts?activeTab=failed');
        $this->line('  Needs Review: /admin/gifts?activeTab=review');
        $this->line('  AI Accepted (spot-check): /admin/gifts?activeTab=ai_accepted');

        $this->newLine();
        $this->comment('Recommended review order:');
        $this->line('  1. FAILED');
        $this->line('  2. trusted-source semantic conflicts');
        $this->line('  3. blocking taxonomy gaps');
        $this->line('  4. low primary Category confidence');
        $this->line('  5. low GiftType confidence');
        $this->line('  6. other REVIEW reasons');

        if ($queued) {
            $this->newLine();
            $this->comment(sprintf(
                'Dispatched %d unique classification jobs (max_concurrency=%d per worker pool). Re-run this command or catalog:curated-audit to inspect progress.',
                $result->queued,
                max(1, (int) config('curated_catalog.classification.max_concurrency', 1)),
            ));
        }

        if ($plan->intakeRunId !== null) {
            $this->newLine();
            $this->comment("Integrity: php artisan catalog:curated-audit --intake-run={$plan->intakeRunId}");
        }
    }

    private function optionString(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function optionInteger(string $name): ?int
    {
        $value = $this->option($name);

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
