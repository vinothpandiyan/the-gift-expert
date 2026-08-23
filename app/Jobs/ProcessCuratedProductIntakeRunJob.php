<?php

namespace App\Jobs;

use App\Actions\CuratedCatalog\ProcessCuratedProductIntakeAction;
use App\Enums\CuratedProductIntakeRunStatus;
use App\Models\CuratedProductIntakeRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Database queue deployments must set retry_after greater than this job's
 * timeout (3600s). Otherwise a still-running sync can be reserved twice.
 */
class ProcessCuratedProductIntakeRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(
        public int $runId,
        public string $json,
        public ?string $formMerchantSlug = null,
        public ?string $formCurationGroup = null,
    ) {}

    public function handle(ProcessCuratedProductIntakeAction $processCuratedProductIntake): void
    {
        $processCuratedProductIntake->processRun(
            $this->runId,
            $this->json,
            $this->formMerchantSlug,
            $this->formCurationGroup,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $run = CuratedProductIntakeRun::query()->find($this->runId);

        if ($run === null || in_array($run->status, [
            CuratedProductIntakeRunStatus::Completed,
            CuratedProductIntakeRunStatus::CompletedWithErrors,
        ], true)) {
            return;
        }

        $run->update([
            'status' => CuratedProductIntakeRunStatus::Failed,
            'finished_at' => now(),
            'error' => $exception?->getMessage() ?? 'The curated sync job failed.',
        ]);
    }
}
