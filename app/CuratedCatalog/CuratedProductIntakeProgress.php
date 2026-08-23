<?php

namespace App\CuratedCatalog;

use Carbon\CarbonInterface;

readonly class CuratedProductIntakeProgress
{
    public function __construct(
        public int $runId,
        public string $status,
        public string $displayPhase,
        public int $total,
        public int $processed,
        public int $remaining,
        public int $created,
        public int $updated,
        public int $skipped,
        public int $failed,
        public int $percentage,
        public bool $isTerminal,
        public bool $showWorkerHint,
        public ?string $error = null,
        public ?CarbonInterface $startedAt = null,
        public ?CarbonInterface $finishedAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'status' => $this->status,
            'display_phase' => $this->displayPhase,
            'total' => $this->total,
            'processed' => $this->processed,
            'remaining' => $this->remaining,
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'percentage' => $this->percentage,
            'is_terminal' => $this->isTerminal,
            'show_worker_hint' => $this->showWorkerHint,
            'error' => $this->error,
            'started_at' => $this->startedAt?->toIso8601String(),
            'finished_at' => $this->finishedAt?->toIso8601String(),
        ];
    }
}
