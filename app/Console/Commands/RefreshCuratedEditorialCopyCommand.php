<?php

namespace App\Console\Commands;

use App\Actions\CuratedCatalog\RefreshCuratedProductEditorialCopyAction;
use App\CommercialSourcing\CommercialEnrichmentException;
use App\Enums\EditorialOwnership;
use App\Enums\ProductStatus;
use App\Models\CuratedProductIntakeItem;
use App\Models\CuratedProductIntakeRun;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RefreshCuratedEditorialCopyCommand extends Command
{
    protected $signature = 'catalog:refresh-curated-editorial
        {--intake-run= : Limit to products from this curated intake run}
        {--product= : Refresh a single product ID}
        {--all : Include all products created by curated intake}
        {--limit= : Maximum products}
        {--dry-run : Plan eligible products without calling AI}
        {--execute : Apply editorial copy writes (implies AI calls)}';

    protected $description = 'Refresh Gift Expert editorial title, short description, and why-it-is-a-great-gift copy without reclassifying or publishing.';

    public function handle(
        RefreshCuratedProductEditorialCopyAction $refresh,
    ): int {
        $intakeRunId = $this->optionInteger('intake-run');
        $productId = $this->optionInteger('product');
        $all = (bool) $this->option('all');
        $scopeCount = (int) ($intakeRunId !== null) + (int) ($productId !== null) + (int) $all;

        if ($scopeCount !== 1) {
            $this->error('Pass exactly one of --intake-run=<id>, --product=<id>, or --all.');

            return self::FAILURE;
        }

        if ($intakeRunId !== null && ! CuratedProductIntakeRun::query()->whereKey($intakeRunId)->exists()) {
            $this->error("Intake run [{$intakeRunId}] was not found.");

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $explicitDryRun = (bool) $this->option('dry-run');
        $limit = $this->optionInteger('limit');

        if ($execute && $explicitDryRun) {
            $this->error('--execute and --dry-run cannot be combined.');

            return self::FAILURE;
        }

        if ($this->option('limit') !== null && $limit === null) {
            $this->error('--limit must be a positive integer.');

            return self::FAILURE;
        }

        if ($execute && $all && $limit === null) {
            $this->error('--all with --execute requires an explicit --limit.');

            return self::FAILURE;
        }

        $scoped = $this->scopedProducts($intakeRunId, $productId, $all);
        $reasons = $scoped
            ->mapWithKeys(fn (Product $product): array => [$product->id => $this->exclusionReason($product)]);
        $eligible = $scoped
            ->filter(fn (Product $product): bool => $reasons->get($product->id) === null)
            ->values();
        $products = $limit === null ? $eligible : $eligible->take($limit)->values();

        $this->reportPlan($scoped, $eligible, $products, $reasons);

        if (! $execute) {
            foreach ($products as $product) {
                $this->line(sprintf(
                    'product=%d ownership=%s generation=%s name=%s',
                    $product->id,
                    $product->editorial_ownership->value,
                    $product->editorial_generation_version ?? 'none',
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

    /**
     * @return Collection<int, Product>
     */
    private function scopedProducts(?int $intakeRunId, ?int $productId, bool $all): Collection
    {
        $query = Product::query()
            ->with('affiliateLinks.merchant:id,slug,name')
            ->orderBy('id');

        if ($productId !== null) {
            $query->whereKey($productId);
        } else {
            $productIds = CuratedProductIntakeItem::query()
                ->when(
                    ! $all,
                    fn ($items) => $items->where('curated_product_intake_run_id', $intakeRunId),
                )
                ->whereNotNull('product_id')
                ->distinct()
                ->pluck('product_id');

            $query->whereIn('id', $productIds);
        }

        return $query->get();
    }

    private function exclusionReason(Product $product): ?string
    {
        if ($product->editorial_ownership === EditorialOwnership::Human) {
            return 'human_owned';
        }

        if ($product->status !== ProductStatus::Draft) {
            return 'not_draft';
        }

        if ($product->editorial_ownership === EditorialOwnership::Ai
            && ! $product->editorialCopyNeedsAiGeneration()) {
            return 'already_ai_generated_current_version';
        }

        if ($product->affiliateLinks->isEmpty()) {
            return 'missing_affiliate_link';
        }

        return null;
    }

    /**
     * @param  Collection<int, Product>  $scoped
     * @param  Collection<int, Product>  $eligible
     * @param  Collection<int, Product>  $selected
     * @param  Collection<int, string|null>  $reasons
     */
    private function reportPlan(
        Collection $scoped,
        Collection $eligible,
        Collection $selected,
        Collection $reasons,
    ): void {
        $rows = [
            ['In scope', $scoped->count()],
            ['Eligible draft non-human copy', $eligible->count()],
            ['Selected after limit', $selected->count()],
        ];

        foreach ($reasons->filter()->countBy()->sortKeys() as $reason => $count) {
            $rows[] = ['Excluded: '.$reason, $count];
        }

        $this->table(['Metric', 'Count'], $rows);
        $this->info(sprintf(
            'Eligible draft products: %d (AI calls if executed: %d)',
            $eligible->count(),
            $selected->count(),
        ));
    }

    private function optionInteger(string $name): ?int
    {
        $value = $this->option($name);

        if (! is_string($value) || $value === '' || ! ctype_digit($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }
}
