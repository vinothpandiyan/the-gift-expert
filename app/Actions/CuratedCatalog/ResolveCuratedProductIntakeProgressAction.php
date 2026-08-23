<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\CuratedProductIntakeProgress;
use App\Enums\CuratedProductIntakeItemOutcome;
use App\Enums\CuratedProductIntakeRunStatus;
use App\Models\CuratedProductIntakeItem;
use App\Models\CuratedProductIntakeRun;

class ResolveCuratedProductIntakeProgressAction
{
    public function execute(CuratedProductIntakeRun $run): CuratedProductIntakeProgress
    {
        $total = (int) $run->items_total;

        $processed = CuratedProductIntakeItem::query()
            ->where('curated_product_intake_run_id', $run->id)
            ->count();

        $outcomeCounts = CuratedProductIntakeItem::query()
            ->where('curated_product_intake_run_id', $run->id)
            ->selectRaw('outcome, COUNT(*) as aggregate_count')
            ->groupBy('outcome')
            ->pluck('aggregate_count', 'outcome');

        $created = (int) ($outcomeCounts[CuratedProductIntakeItemOutcome::Created->value] ?? 0);
        $updated = (int) ($outcomeCounts[CuratedProductIntakeItemOutcome::Updated->value] ?? 0);
        $skipped = (int) ($outcomeCounts[CuratedProductIntakeItemOutcome::Skipped->value] ?? 0);
        $failed = (int) ($outcomeCounts[CuratedProductIntakeItemOutcome::Failed->value] ?? 0);

        $remaining = max($total - $processed, 0);
        $percentage = $total > 0 ? (int) floor(($processed / $total) * 100) : 0;

        $isTerminal = in_array($run->status, [
            CuratedProductIntakeRunStatus::Completed,
            CuratedProductIntakeRunStatus::CompletedWithErrors,
            CuratedProductIntakeRunStatus::Failed,
        ], true);

        $displayPhase = $this->resolveDisplayPhase($run, $processed);
        $showWorkerHint = $displayPhase === 'waiting_for_worker';

        return new CuratedProductIntakeProgress(
            runId: $run->id,
            status: $run->status->value,
            displayPhase: $displayPhase,
            total: $total,
            processed: $processed,
            remaining: $remaining,
            created: $created,
            updated: $updated,
            skipped: $skipped,
            failed: $failed,
            percentage: min($percentage, 100),
            isTerminal: $isTerminal,
            showWorkerHint: $showWorkerHint,
            error: $run->error,
            startedAt: $run->started_at,
            finishedAt: $run->finished_at,
        );
    }

    private function resolveDisplayPhase(CuratedProductIntakeRun $run, int $processed): string
    {
        if ($run->status === CuratedProductIntakeRunStatus::Failed) {
            return 'failed';
        }

        if ($run->status === CuratedProductIntakeRunStatus::CompletedWithErrors) {
            return 'completed_with_errors';
        }

        if ($run->status === CuratedProductIntakeRunStatus::Completed) {
            return 'completed';
        }

        if ($processed > 0) {
            return 'processing';
        }

        $graceSeconds = (int) config('curated_catalog.sync.worker_wait_seconds', 10);

        if ($run->started_at !== null && $run->started_at->diffInSeconds(now()) < $graceSeconds) {
            return 'starting';
        }

        return 'waiting_for_worker';
    }
}
