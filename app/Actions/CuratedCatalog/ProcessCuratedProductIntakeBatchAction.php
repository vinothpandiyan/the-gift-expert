<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\CuratedProductIntakeBatchReport;
use App\CuratedCatalog\CuratedProductIntakeCommitResult;
use App\Enums\CuratedProductIntakeRunStatus;
use App\Enums\CuratedProductIntakeSourceType;
use App\Models\CuratedProductIntakeRun;
use App\Models\Merchant;

class ProcessCuratedProductIntakeBatchAction
{
    public function __construct(
        private AssembleCuratedProductIntakeBatchAction $assemble,
        private ProcessCuratedProductIntakeAction $process,
        private AssertCuratedProductIntakeBatchIsImportableAction $assertImportable,
    ) {}

    public function dryRun(string $path): CuratedProductIntakeBatchReport
    {
        return $this->assemble->execute($path);
    }

    public function commit(
        string $path,
        bool $deferClassification = false,
        bool $allowUnknownSourceLists = false,
        ?int $createdByUserId = null,
    ): CuratedProductIntakeCommitResult {
        $report = $this->assemble->execute($path);
        $this->assertImportable->execute($report, $allowUnknownSourceLists);

        $merchant = Merchant::query()
            ->where('slug', $report->merchantSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $run = CuratedProductIntakeRun::query()->create([
            'merchant_id' => $merchant->id,
            'source_type' => CuratedProductIntakeSourceType::CliJson,
            'status' => CuratedProductIntakeRunStatus::Processing,
            'started_at' => now(),
            'items_total' => count($report->preview->items),
            'raw_occurrences' => $report->rawOccurrences,
            'unique_products' => $report->uniqueProducts,
            'merged_occurrences' => $report->mergedOccurrences,
            'created_by_user_id' => $createdByUserId,
        ]);

        $this->process->processAssembled(
            $run,
            $merchant,
            $report->preview,
            $deferClassification,
        );

        return $this->process->resultFromRun($run->fresh());
    }
}
