<?php

namespace App\Actions\LaunchPublication;

use App\Actions\CatalogCuration\ResolveAcceptedCurationAuditRunAction;
use App\Actions\Product\QueryPublishedProductsByFiltersAction;
use App\Enums\GiftIntent;
use App\Models\BudgetRange;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class BuildLaunchCatalogCoverageAction
{
    public function __construct(
        private ResolveAcceptedCurationAuditRunAction $resolveAcceptedRun,
        private QueryPublishedProductsByFiltersAction $queryPublished,
    ) {}

    /**
     * @param  list<int>  $productIds
     * @return array<string, mixed>
     */
    public function execute(array $productIds, bool $includeLandingPages = true): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));

        $products = Product::query()
            ->whereIn('id', $ids)
            ->with(['relationships', 'occasions', 'interests', 'giftTypes'])
            ->orderBy('id')
            ->get();

        $audits = $this->auditsFor($products);
        $budgetRanges = BudgetRange::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $budget = $this->budgetCoverage($products, $budgetRanges);
        $relationships = $this->dimensionCoverage(
            Relationship::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            $products,
            'relationships',
        );
        $occasions = $this->dimensionCoverage(
            Occasion::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            $products,
            'occasions',
        );
        $interests = $this->dimensionCoverage(
            Interest::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            $products,
            'interests',
        );
        $giftTypes = $this->dimensionCoverage(
            GiftType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            $products,
            'giftTypes',
        );
        $intents = $this->intentCoverage($products, $audits);

        $landingPages = $includeLandingPages
            ? $this->landingPageCoverage()
            : [];

        return [
            'product_count' => $products->count(),
            'budget' => $budget,
            'relationships' => $relationships,
            'occasions' => $occasions,
            'interests' => $interests,
            'gift_types' => $giftTypes,
            'gift_intents' => $intents,
            'landing_pages' => $landingPages,
            'holes' => $this->holes($budget, $relationships, $occasions, $interests, $giftTypes, $intents, $landingPages),
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, BudgetRange>  $budgetRanges
     * @return list<array{slug: string, name: string, count: int}>
     */
    private function budgetCoverage(Collection $products, Collection $budgetRanges): array
    {
        $counts = [];
        foreach ($budgetRanges as $range) {
            $counts[$range->slug] = [
                'slug' => $range->slug,
                'name' => $range->name,
                'count' => 0,
            ];
        }
        $counts['no-price'] = [
            'slug' => 'no-price',
            'name' => 'No price',
            'count' => 0,
        ];

        foreach ($products as $product) {
            $matched = false;
            foreach ($budgetRanges as $range) {
                if ($product->price_currency && strtoupper((string) $product->price_currency) === strtoupper((string) $range->currency)
                    && $range->containsAmount($product->price_amount)) {
                    $counts[$range->slug]['count']++;
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $counts['no-price']['count']++;
            }
        }

        return array_values($counts);
    }

    /**
     * @param  Collection<int, Model>  $taxonomy
     * @param  Collection<int, Product>  $products
     * @return list<array{id: int, slug: string, name: string, count: int}>
     */
    private function dimensionCoverage(Collection $taxonomy, Collection $products, string $relation): array
    {
        return $taxonomy->map(function ($record) use ($products, $relation): array {
            $count = $products->filter(
                fn (Product $product): bool => $product->{$relation}->contains('id', $record->id),
            )->count();

            return [
                'id' => (int) $record->id,
                'slug' => (string) $record->slug,
                'name' => (string) $record->name,
                'count' => $count,
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, ProductCurationAudit>  $audits
     * @return list<array{value: string, name: string, count: int}>
     */
    private function intentCoverage(Collection $products, Collection $audits): array
    {
        $counts = [];
        foreach (GiftIntent::cases() as $intent) {
            $counts[$intent->value] = 0;
        }

        foreach ($products as $product) {
            $intents = (array) ($audits->get($product->id)?->gift_intents ?? []);
            foreach ($intents as $intent) {
                if (is_string($intent) && array_key_exists($intent, $counts)) {
                    $counts[$intent]++;
                }
            }
        }

        return array_map(
            fn (GiftIntent $intent): array => [
                'value' => $intent->value,
                'name' => str($intent->name)->headline()->toString(),
                'count' => $counts[$intent->value],
            ],
            GiftIntent::cases(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function landingPageCoverage(): array
    {
        $sparse = (int) config('gift_publication.launch.sparse_landing_page_count', 2);

        return SeoLandingPage::query()
            ->discoverable()
            ->with('interests')
            ->orderBy('sort_order')
            ->orderBy('heading')
            ->get()
            ->map(function (SeoLandingPage $page) use ($sparse): array {
                $filters = array_filter([
                    'relationship_id' => $page->relationship_id,
                    'occasion_id' => $page->occasion_id,
                    'recipient_type_id' => $page->recipient_type_id,
                    'profession_id' => $page->profession_id,
                    'gift_type_id' => $page->gift_type_id,
                    'category_id' => $page->category_id,
                    'budget_range_id' => $page->budget_range_id,
                    'interest_ids' => $page->interests->pluck('id')->all(),
                ], fn (mixed $value): bool => $value !== null && $value !== []);

                $count = 0;
                if ($filters !== []) {
                    $count = $this->queryPublished->execute($filters)->count();
                }

                return [
                    'id' => (int) $page->id,
                    'name' => (string) $page->name,
                    'slug' => (string) $page->slug,
                    'heading' => (string) $page->heading,
                    'published_count' => $count,
                    'sparse' => $count < $sparse,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array{slug: string, name: string, count: int}>  $budget
     * @param  list<array{id: int, slug: string, name: string, count: int}>  $relationships
     * @param  list<array{id: int, slug: string, name: string, count: int}>  $occasions
     * @param  list<array{id: int, slug: string, name: string, count: int}>  $interests
     * @param  list<array{id: int, slug: string, name: string, count: int}>  $giftTypes
     * @param  list<array{value: string, name: string, count: int}>  $intents
     * @param  list<array<string, mixed>>  $landingPages
     * @return list<array{level: string, dimension: string, label: string, count: int}>
     */
    private function holes(
        array $budget,
        array $relationships,
        array $occasions,
        array $interests,
        array $giftTypes,
        array $intents,
        array $landingPages,
    ): array {
        $holes = [];

        foreach ($budget as $row) {
            if ($row['slug'] === 'no-price' && $row['count'] > 0) {
                $holes[] = ['level' => 'high', 'dimension' => 'budget', 'label' => $row['name'], 'count' => $row['count']];
            } elseif ($row['count'] === 0 && $row['slug'] !== 'no-price') {
                $level = in_array($row['slug'], ['under-500', '500-1000', '1000-2500'], true) ? 'critical' : 'high';
                $holes[] = ['level' => $level, 'dimension' => 'budget', 'label' => $row['name'], 'count' => 0];
            }
        }

        $coreRelationships = ['husband', 'wife', 'boyfriend', 'girlfriend', 'mother', 'father', 'friend'];
        foreach ($relationships as $row) {
            if ($row['count'] === 0) {
                $level = in_array($row['slug'], $coreRelationships, true) ? 'critical' : 'medium';
                $holes[] = ['level' => $level, 'dimension' => 'relationship', 'label' => $row['name'], 'count' => 0];
            } elseif ($row['count'] === 1 && in_array($row['slug'], $coreRelationships, true)) {
                $holes[] = ['level' => 'high', 'dimension' => 'relationship', 'label' => $row['name'], 'count' => 1];
            }
        }

        $coreOccasions = ['birthday', 'anniversary', 'wedding', 'valentines-day', 'diwali', 'christmas'];
        foreach ($occasions as $row) {
            if ($row['count'] === 0) {
                $level = in_array($row['slug'], $coreOccasions, true) ? 'critical' : 'medium';
                $holes[] = ['level' => $level, 'dimension' => 'occasion', 'label' => $row['name'], 'count' => 0];
            }
        }

        foreach ($interests as $row) {
            if ($row['count'] === 0) {
                $holes[] = ['level' => 'medium', 'dimension' => 'interest', 'label' => $row['name'], 'count' => 0];
            } elseif ($row['count'] === 1) {
                $holes[] = ['level' => 'low', 'dimension' => 'interest', 'label' => $row['name'], 'count' => 1];
            }
        }

        foreach ($giftTypes as $row) {
            if ($row['count'] === 0) {
                $holes[] = ['level' => 'medium', 'dimension' => 'gift_type', 'label' => $row['name'], 'count' => 0];
            }
        }

        foreach ($intents as $row) {
            if ($row['count'] === 0) {
                $level = in_array($row['value'], ['romantic', 'practical', 'personalised', 'premium'], true) ? 'high' : 'medium';
                $holes[] = ['level' => $level, 'dimension' => 'gift_intent', 'label' => $row['name'], 'count' => 0];
            }
        }

        foreach ($landingPages as $page) {
            if (($page['sparse'] ?? false) === true) {
                $holes[] = [
                    'level' => ($page['published_count'] ?? 0) === 0 ? 'high' : 'medium',
                    'dimension' => 'seo_landing_page',
                    'label' => $page['heading'] ?: $page['name'],
                    'count' => (int) ($page['published_count'] ?? 0),
                ];
            }
        }

        return $holes;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, ProductCurationAudit>
     */
    private function auditsFor(Collection $products): Collection
    {
        $run = $this->resolveAcceptedRun->execute();

        if (! $run instanceof ProductCurationAuditRun || $products->isEmpty()) {
            return collect();
        }

        return ProductCurationAudit::query()
            ->where('run_id', $run->id)
            ->whereIn('product_id', $products->modelKeys())
            ->get()
            ->keyBy(fn (ProductCurationAudit $audit): int => (int) $audit->product_id);
    }
}
