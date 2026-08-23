<?php

namespace Tests\Support;

use App\Actions\CuratedCatalog\ProcessCuratedProductIntakeAction;
use App\CuratedCatalog\CuratedProductIntakeCommitResult;
use App\Models\CuratedProductIntakeRun;
use Illuminate\Support\Facades\Queue;

trait ProcessesCuratedSyncRuns
{
    protected function runCuratedSync(
        string $payload,
        ?string $merchantSlug = 'amazon-in',
        ?string $curationGroup = null,
    ): CuratedProductIntakeCommitResult {
        Queue::fake();

        $process = app(ProcessCuratedProductIntakeAction::class);
        $started = $process->start($payload, $merchantSlug, $curationGroup);

        $process->processRun($started->runId, $payload, $merchantSlug, $curationGroup);

        return $process->resultFromRun(CuratedProductIntakeRun::query()->findOrFail($started->runId));
    }

    protected function dispatchCuratedSync(
        string $payload,
        ?string $merchantSlug = 'amazon-in',
        ?string $curationGroup = null,
    ): int {
        config(['queue.default' => 'sync']);

        $process = app(ProcessCuratedProductIntakeAction::class);

        return $process->start($payload, $merchantSlug, $curationGroup)->runId;
    }
}
