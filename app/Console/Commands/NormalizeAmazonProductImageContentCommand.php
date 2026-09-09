<?php

namespace App\Console\Commands;

use App\Actions\ProductImage\NormalizeAmazonProductImageContentAction;
use App\Models\CuratedProductIntakeRun;
use App\ProductImage\AmazonImageContentNormalizationResult;
use Illuminate\Console\Command;

class NormalizeAmazonProductImageContentCommand extends Command
{
    protected $signature = 'catalog:normalize-amazon-image-content
        {--intake-run= : Limit to products from this curated intake run}
        {--product= : Normalize a single product ID}
        {--limit= : Maximum images to consider}
        {--dry-run : Analyze and report without changing stored images}';

    protected $description = 'Conservatively remove uniform outer background from automatically acquired Amazon image derivatives.';

    public function handle(NormalizeAmazonProductImageContentAction $normalize): int
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

        $result = $normalize->execute(
            intakeRunId: $intakeRunId,
            productId: $productId,
            limit: $this->optionInteger('limit'),
            dryRun: (bool) $this->option('dry-run'),
        );

        $this->printResult($result);

        if ($result->dryRun) {
            $this->comment('Dry run completed. No image files, source data, taxonomy, classification, editorial copy, or publication state were changed.');
        }

        return $result->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function printResult(AmazonImageContentNormalizationResult $result): void
    {
        $this->table(
            ['Metric', 'Count'],
            [
                ['Examined', $result->examined],
                [$result->dryRun ? 'Would normalize' : 'Normalized', $result->normalized],
                ['Skipped operator-managed', $result->skippedOperatorManaged],
                ['Skipped non-Amazon', $result->skippedNotAmazon],
                ['Skipped unsafe/uncertain', $result->skippedUnsafe],
                ['Skipped already normalized', $result->skippedAlreadyNormalized],
                ['Failed', $result->failed],
                ['Average long-edge occupancy before', $this->percentage($result->averageOccupancyBefore)],
                ['Average long-edge occupancy after', $this->percentage($result->averageOccupancyAfter)],
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

    private function percentage(float $ratio): string
    {
        return number_format($ratio * 100, 1).'%';
    }

    private function optionInteger(string $name): ?int
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' && ctype_digit($value)
            ? (int) $value
            : null;
    }
}
