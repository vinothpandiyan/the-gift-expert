<?php

namespace App\Console\Commands;

use App\Actions\CuratedCatalog\QueryProductsForCuratedClassificationAction;
use App\Actions\CuratedCatalog\RefreshCuratedProductEditorialCopyAction;
use App\CommercialSourcing\CommercialEnrichmentException;
use App\Enums\ProductStatus;
use App\Models\CuratedProductIntakeRun;
use App\Models\Product;
use Illuminate\Console\Command;

class RefreshCuratedEditorialCopyCommand extends Command
{
    protected $signature = 'catalog:refresh-curated-editorial
        {--intake-run= : Limit to products from this curated intake run}
        {--product= : Refresh a single product ID}
        {--limit= : Maximum products}
        {--dry-run : Plan eligible products without calling AI}
        {--execute : Apply editorial copy writes (implies AI calls)}';

    protected $description = 'Refresh Gift Expert editorial title, short description, and why-it-is-a-great-gift copy without reclassifying or publishing.';

    public function handle(
        QueryProductsForCuratedClassificationAction $queryProducts,
        RefreshCuratedProductEditorialCopyAction $refresh,
    ): int {
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

        $execute = (bool) $this->option('execute');
        $dryRun = (bool) $this->option('dry-run') || ! $execute;

        $products = $queryProducts->execute(
            $intakeRunId,
            $productId,
            null,
            null,
            $this->optionInteger('limit'),
        )->filter(fn (Product $product): bool => $product->status === ProductStatus::Draft)
            ->values();

        $this->info(sprintf(
            'Eligible draft products: %d (AI calls if executed: %d)',
            $products->count(),
            $products->count(),
        ));

        if ($dryRun && ! $execute) {
            foreach ($products as $product) {
                $this->line(sprintf(
                    'product=%d status=%s name=%s',
                    $product->id,
                    $product->taxonomy_classification_status?->value ?? 'none',
                    $product->name,
                ));
            }

            $this->comment('Dry run completed. No AI calls, taxonomy, classification, or publication changes.');
            $this->comment('Pass --execute to apply editorial copy. This will not reclassify.');

            return self::SUCCESS;
        }

        $updated = 0;
        $failed = 0;

        foreach ($products as $index => $product) {
            try {
                $refresh->execute($product);
                $updated++;
                $this->line(sprintf(
                    '[%d/%d] product=%d updated',
                    $index + 1,
                    $products->count(),
                    $product->id,
                ));
            } catch (CommercialEnrichmentException $exception) {
                $failed++;
                $this->warn(sprintf(
                    '[%d/%d] product=%d failed: %s',
                    $index + 1,
                    $products->count(),
                    $product->id,
                    $exception->getMessage(),
                ));
            }
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Eligible', $products->count()],
                ['Updated', $updated],
                ['Failed', $failed],
                ['AI calls', $updated + $failed],
            ],
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
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
