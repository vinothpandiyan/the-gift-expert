<?php

namespace App\Console\Commands;

use App\Actions\CuratedCatalog\GenerateCuratedProductSeoAction;
use App\CommercialSourcing\CommercialEnrichmentException;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class GenerateProductSeoCommand extends Command
{
    protected $signature = 'catalog:generate-product-seo
        {--product= : Generate SEO for one draft product ID}
        {--all : Consider all eligible draft products}
        {--limit= : Maximum products; required with --all --execute}
        {--dry-run : Show the representative selection without calling AI}
        {--execute : Generate and persist SEO metadata}';

    protected $description = 'Generate protected product meta titles and descriptions from Gift Expert editorial copy and taxonomy.';

    public function handle(GenerateCuratedProductSeoAction $generate): int
    {
        $productId = $this->optionInteger('product');
        $all = (bool) $this->option('all');

        if (((int) ($productId !== null) + (int) $all) !== 1) {
            $this->error('Pass exactly one of --product=<id> or --all.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->optionInteger('limit');

        if ($execute && $dryRun) {
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

        $eligible = $this->eligibleProducts($productId);
        $selected = $all
            ? $this->representativeSelection($eligible, $limit)
            : $eligible->take(1)->values();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Eligible draft products', $eligible->count()],
                ['Representative selection', $selected->count()],
            ],
        );

        foreach ($selected as $product) {
            $this->line(sprintf(
                'product=%d classification=%s primary_category=%s title=%s',
                $product->id,
                $product->taxonomy_classification_status?->value ?? 'none',
                $product->categories->firstWhere('pivot.is_primary', true)?->name ?? 'none',
                $product->name,
            ));
        }

        if (! $execute) {
            $this->comment('Dry run completed. No AI calls or writes were made.');

            return self::SUCCESS;
        }

        if ($all && $limit !== null && $selected->count() !== $limit) {
            $this->error("Requested {$limit} products, but only {$selected->count()} eligible products were available. No SEO was generated.");

            return self::FAILURE;
        }

        $updated = 0;
        $failed = 0;

        foreach ($selected as $index => $product) {
            try {
                $generate->execute($product);
                $updated++;
                $this->line(sprintf('[%d/%d] product=%d generated', $index + 1, $selected->count(), $product->id));
            } catch (CommercialEnrichmentException $exception) {
                $failed++;
                $this->warn(sprintf(
                    '[%d/%d] product=%d failed: %s',
                    $index + 1,
                    $selected->count(),
                    $product->id,
                    $exception->getMessage(),
                ));
            }
        }

        $this->table(['Generated', 'Failed', 'AI calls'], [[$updated, $failed, $updated + $failed]]);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return Collection<int, Product>
     */
    private function eligibleProducts(?int $productId): Collection
    {
        return Product::query()
            ->with([
                'categories:id,name',
                'relationships:id,name',
                'recipientTypes:id,name',
                'occasions:id,name',
                'interests:id,name',
                'professions:id,name',
                'giftTypes:id,name',
            ])
            ->where('status', ProductStatus::Draft)
            ->whereHas('affiliateLinks')
            ->whereHas('images')
            ->when($productId !== null, fn ($query) => $query->whereKey($productId))
            ->orderBy('id')
            ->get()
            ->filter(fn (Product $product): bool => $product->seoNeedsAiGeneration()
                && filled($product->name)
                && (filled($product->short_description) || filled($product->description)))
            ->values();
    }

    /**
     * Round-robin across classification and primary-category groups.
     *
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    private function representativeSelection(Collection $products, ?int $limit): Collection
    {
        $groups = $products
            ->groupBy(function (Product $product): string {
                $categoryId = $product->categories
                    ->first(fn ($category): bool => (bool) ($category->pivot->is_primary ?? false))
                    ?->id;

                return ($product->taxonomy_classification_status?->value ?? 'none').'|'.($categoryId ?? 'none');
            })
            ->map(fn (Collection $group): Collection => $group->values());
        $selected = collect();
        $target = $limit ?? $products->count();

        while ($selected->count() < $target && $groups->isNotEmpty()) {
            foreach ($groups->keys() as $key) {
                $group = $groups->get($key);
                $product = $group?->shift();

                if ($product instanceof Product) {
                    $selected->push($product);
                }

                if ($group === null || $group->isEmpty()) {
                    $groups->forget($key);
                }

                if ($selected->count() >= $target) {
                    break 2;
                }
            }
        }

        return $selected->values();
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
