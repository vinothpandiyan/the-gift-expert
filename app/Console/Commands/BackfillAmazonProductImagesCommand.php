<?php

namespace App\Console\Commands;

use App\Actions\ProductImage\BackfillAmazonProductImagesAction;
use App\Models\CuratedProductIntakeRun;
use App\ProductImage\AmazonProductImageBackfillResult;
use Illuminate\Console\Command;

class BackfillAmazonProductImagesCommand extends Command
{
    protected $signature = 'catalog:backfill-amazon-images
        {--intake-run= : Limit to products from this curated intake run}
        {--product= : Backfill a single product ID}
        {--limit= : Maximum images to consider}
        {--dry-run : Report eligible images without downloading}';

    protected $description = 'Replace automatically acquired low-resolution Amazon ProductImages with a high-quality canonical source. Does not call AI, classify, or publish.';

    public function handle(BackfillAmazonProductImagesAction $backfill): int
    {
        $intakeRunId = $this->optionInteger('intake-run');
        $productId = $this->optionInteger('product');

        if ($intakeRunId === null && $productId === null) {
            $this->error('Pass --intake-run=<id> or --product=<id>.');

            return self::FAILURE;
        }

        if ($intakeRunId !== null && ! CuratedProductIntakeRun::query()->whereKey($intakeRunId)->exists()) {
            $this->error("Intake run [{$intakeRunId}] was not found.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $result = $backfill->execute(
            $intakeRunId,
            $productId,
            $this->optionInteger('limit'),
            $dryRun,
        );

        $this->printResult($result);

        if ($dryRun) {
            $this->comment('Dry run completed. No image files, taxonomy, classification, or publication were changed.');
        }

        return $result->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function printResult(AmazonProductImageBackfillResult $result): void
    {
        $this->table(
            ['Metric', 'Count'],
            [
                ['Examined', $result->examined],
                [$result->dryRun ? 'Would replace' : 'Replaced', $result->replaced],
                ['Skipped operator-managed', $result->skippedOperatorManaged],
                ['Skipped non-Amazon', $result->skippedNotAmazon],
                ['Skipped already high-resolution', $result->skippedAlreadyHighResolution],
                ['Failed', $result->failed],
            ],
        );

        foreach ($result->failures as $failure) {
            $this->warn(sprintf(
                'product=%d image=%d reason=%s',
                $failure['product_id'],
                $failure['image_id'],
                $failure['reason'],
            ));
        }
    }

    private function optionInteger(string $name): ?int
    {
        $value = $this->option($name);

        if (! is_string($value) || $value === '' || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
