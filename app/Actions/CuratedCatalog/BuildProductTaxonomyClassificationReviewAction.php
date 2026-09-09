<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\ProductTaxonomyClassificationReview;
use App\CuratedCatalog\ProductTaxonomyFormState;
use App\Enums\CatalogSourceListKind;
use App\Enums\TaxonomyClassificationStatus;
use App\Enums\TaxonomyClassificationWarningCode;
use App\Enums\TaxonomyDimension;
use App\Models\AffiliateLink;
use App\Models\CatalogProductSource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;

class BuildProductTaxonomyClassificationReviewAction
{
    public function __construct(
        private DetectStaleCuratedTaxonomyProposalAction $detectStale,
        private ResolveCatalogSourceRelationshipHintsAction $resolveHints,
    ) {}

    public function execute(Product $product): ProductTaxonomyClassificationReview
    {
        $product->loadMissing([
            'images',
            'affiliateLinks.merchant',
            'affiliateLinks.catalogProductSources.sourceList.relationship',
            'taxonomyApprovedBy',
            'categories',
            'relationships',
            'recipientTypes',
            'occasions',
            'interests',
            'professions',
            'giftTypes',
        ]);

        $link = $this->primaryLink($product);
        $proposal = is_array($product->taxonomy_classification_proposal)
            ? $product->taxonomy_classification_proposal
            : [];
        $confidence = is_array($proposal['confidence'] ?? null) ? $proposal['confidence'] : [];
        $thresholds = config('curated_catalog.taxonomy_classification.thresholds', []);

        $trustedHintIds = $this->resolveHints->execute($product);
        $proposedRelationshipIds = $this->idList($proposal['relationship_ids'] ?? []);
        $reasoningBlocks = $this->reasoningBlocks($product, $proposal);

        return new ProductTaxonomyClassificationReview(
            title: (string) $product->name,
            imageUrl: $this->imageUrl($product),
            merchantName: $link?->merchant?->name,
            externalProductId: $link?->external_product_id,
            priceDisplay: $this->priceDisplay($product),
            availabilityLabel: $this->availabilityLabel($link?->availability),
            productStatus: ucfirst($product->status?->value ?? 'draft'),
            classificationStatus: $product->taxonomy_classification_status ?? TaxonomyClassificationStatus::None,
            classificationVersion: $product->taxonomy_classification_version,
            classifiedAt: $product->taxonomy_classified_at?->toDateTimeString(),
            approvedAt: $product->taxonomy_approved_at?->toDateTimeString(),
            approvedBy: $product->taxonomyApprovedBy?->name,
            proposalPending: $product->taxonomyProposalIsPending(),
            proposalStale: $proposal !== [] && $this->detectStale->execute($product),
            reviewReasons: $this->codes($product->taxonomy_review_reasons ?? []),
            warnings: $this->codes($product->taxonomy_classification_warnings ?? []),
            gapSuggestion: $product->taxonomy_gap_suggestion,
            gapExplanation: $product->taxonomy_gap_explanation,
            reasoning: $this->reasoningText($reasoningBlocks),
            reasoningBlocks: $reasoningBlocks,
            proposalDimensions: $this->proposalDimensions($proposal, $confidence, $thresholds),
            appliedDimensions: $this->appliedDimensions($product),
            provenance: $this->provenance($product),
            trustedHintNames: ProductTaxonomyFormState::names(TaxonomyDimension::Relationship, $trustedHintIds),
            proposedRelationshipNames: ProductTaxonomyFormState::names(TaxonomyDimension::Relationship, $proposedRelationshipIds),
        );
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $confidence
     * @param  array<string, mixed>  $thresholds
     * @return list<array{label: string, names: list<string>, items: list<array{name: string}>, confidence: ?string, below_threshold: bool}>
     */
    private function proposalDimensions(array $proposal, array $confidence, array $thresholds): array
    {
        if ($proposal === []) {
            return [];
        }

        $primaryId = $this->nullableId($proposal['primary_category_id'] ?? null);
        $categoryIds = $this->idList($proposal['category_ids'] ?? []);

        if (
            $primaryId === null
            && $categoryIds === []
            && $this->idList($proposal['relationship_ids'] ?? []) === []
            && $this->idList($proposal['occasion_ids'] ?? []) === []
            && $this->idList($proposal['interest_ids'] ?? []) === []
            && $this->idList($proposal['recipient_type_ids'] ?? []) === []
            && $this->idList($proposal['profession_ids'] ?? []) === []
            && $this->idList($proposal['gift_type_ids'] ?? []) === []
        ) {
            return [];
        }
        $ancestorIds = array_values(array_filter(
            $categoryIds,
            fn (int $id): bool => $id !== $primaryId,
        ));

        $primaryThreshold = (float) ($thresholds['primary_category_auto_accept'] ?? 0.85);
        $giftTypeThreshold = (float) ($thresholds['gift_type_auto_accept'] ?? 0.80);

        return [
            $this->dimensionRow(
                'Primary Category',
                $primaryId !== null ? ProductTaxonomyFormState::names(TaxonomyDimension::Category, [$primaryId]) : [],
                $this->score($confidence['primary_category'] ?? null),
                $primaryThreshold,
            ),
            $this->dimensionRow(
                'Derived Category ancestors',
                ProductTaxonomyFormState::names(TaxonomyDimension::Category, $ancestorIds),
                null,
                null,
            ),
            $this->dimensionRow(
                'Relationships',
                ProductTaxonomyFormState::names(TaxonomyDimension::Relationship, $this->idList($proposal['relationship_ids'] ?? [])),
                $this->score($confidence['relationships'] ?? null),
                null,
            ),
            $this->dimensionRow(
                'Recipient types',
                ProductTaxonomyFormState::names(TaxonomyDimension::RecipientType, $this->idList($proposal['recipient_type_ids'] ?? [])),
                $this->score($confidence['recipient_types'] ?? null),
                null,
            ),
            $this->dimensionRow(
                'Occasions',
                ProductTaxonomyFormState::names(TaxonomyDimension::Occasion, $this->idList($proposal['occasion_ids'] ?? [])),
                $this->score($confidence['occasions'] ?? null),
                null,
            ),
            $this->dimensionRow(
                'Interests',
                ProductTaxonomyFormState::names(TaxonomyDimension::Interest, $this->idList($proposal['interest_ids'] ?? [])),
                $this->score($confidence['interests'] ?? null),
                null,
            ),
            $this->dimensionRow(
                'Professions',
                ProductTaxonomyFormState::names(TaxonomyDimension::Profession, $this->idList($proposal['profession_ids'] ?? [])),
                $this->score($confidence['professions'] ?? null),
                null,
            ),
            $this->dimensionRow(
                'Gift types',
                ProductTaxonomyFormState::names(TaxonomyDimension::GiftType, $this->idList($proposal['gift_type_ids'] ?? [])),
                $this->score($confidence['gift_types'] ?? null),
                $giftTypeThreshold,
            ),
        ];
    }

    /**
     * @return list<array{label: string, names: list<string>, items: list<array{name: string}>}>
     */
    private function appliedDimensions(Product $product): array
    {
        $primary = $product->categories
            ->first(fn (Category $category): bool => (bool) $category->pivot->is_primary);
        $ancestors = $product->categories
            ->filter(fn (Category $category): bool => ! (bool) $category->pivot->is_primary)
            ->sortBy('full_path')
            ->values();

        return [
            $this->appliedRow(
                'Primary Category',
                $primary instanceof Category ? [(string) $primary->name] : [],
            ),
            $this->appliedRow(
                'Derived Category ancestors',
                $ancestors
                    ->map(fn (Category $category): string => (string) $category->name)
                    ->all(),
            ),
            $this->appliedRow(
                'Relationships',
                $product->relationships->sortBy('name')->pluck('name')->map(fn ($name): string => (string) $name)->all(),
            ),
            $this->appliedRow(
                'Recipient types',
                $product->recipientTypes->sortBy('name')->pluck('name')->map(fn ($name): string => (string) $name)->all(),
            ),
            $this->appliedRow(
                'Occasions',
                $product->occasions->sortBy('name')->pluck('name')->map(fn ($name): string => (string) $name)->all(),
            ),
            $this->appliedRow(
                'Interests',
                $product->interests->sortBy('name')->pluck('name')->map(fn ($name): string => (string) $name)->all(),
            ),
            $this->appliedRow(
                'Professions',
                $product->professions->sortBy('name')->pluck('name')->map(fn ($name): string => (string) $name)->all(),
            ),
            $this->appliedRow(
                'Gift types',
                $product->giftTypes->sortBy('name')->pluck('name')->map(fn ($name): string => (string) $name)->all(),
            ),
        ];
    }

    /**
     * @return list<array{name: string, kind: string, relationship: ?string, first_seen: ?string, last_seen: ?string, is_trusted_hint: bool}>
     */
    private function provenance(Product $product): array
    {
        $rows = [];

        foreach ($product->affiliateLinks as $link) {
            foreach ($link->catalogProductSources as $source) {
                /** @var CatalogProductSource $source */
                $list = $source->sourceList;
                $kind = $list?->kind instanceof CatalogSourceListKind
                    ? ($list->kind->getLabel() ?? $list->kind->value)
                    : 'Unknown';
                $isHint = $list?->kind === CatalogSourceListKind::RecipientHint && $list->relationship_id !== null;

                $rows[] = [
                    'name' => $list?->name ?? 'Unknown source list',
                    'kind' => $kind,
                    'relationship' => $isHint ? ($list?->relationship?->name) : null,
                    'first_seen' => $source->first_seen_at?->toDateTimeString(),
                    'last_seen' => $source->last_seen_at?->toDateTimeString(),
                    'is_trusted_hint' => $isHint,
                ];
            }
        }

        usort($rows, function (array $left, array $right): int {
            return [$right['is_trusted_hint'] ? 1 : 0, $left['name']] <=> [$left['is_trusted_hint'] ? 1 : 0, $right['name']];
        });

        return $rows;
    }

    /**
     * @param  list<mixed>  $codes
     * @return list<array{code: string, label: string, description: string}>
     */
    private function codes(array $codes): array
    {
        $rows = [];

        foreach ($codes as $code) {
            if (! is_string($code) || $code === '') {
                continue;
            }

            $rows[] = [
                'code' => $code,
                'label' => TaxonomyClassificationWarningCode::labelFor($code),
                'description' => TaxonomyClassificationWarningCode::descriptionFor($code),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $names
     * @return array{label: string, names: list<string>, items: list<array{name: string}>, confidence: ?string, below_threshold: bool}
     */
    private function dimensionRow(string $label, array $names, ?float $score, ?float $threshold): array
    {
        $below = $score !== null && $threshold !== null && $score < $threshold;

        return [
            'label' => $label,
            'names' => $names,
            'items' => $this->namedItems($names),
            'confidence' => $score !== null ? ((string) (int) round($score * 100)).'%' : null,
            'below_threshold' => $below,
        ];
    }

    /**
     * @param  list<string>  $names
     * @return array{label: string, names: list<string>, items: list<array{name: string}>}
     */
    private function appliedRow(string $label, array $names): array
    {
        return [
            'label' => $label,
            'names' => $names,
            'items' => $this->namedItems($names),
        ];
    }

    /**
     * @param  list<string>  $names
     * @return list<array{name: string}>
     */
    private function namedItems(array $names): array
    {
        return array_values(array_map(
            fn (string $name): array => ['name' => $name],
            $names,
        ));
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return list<array{label: string, text: string}>
     */
    private function reasoningBlocks(Product $product, array $proposal): array
    {
        $summary = $product->taxonomy_reasoning;

        if (! is_array($summary) || $summary === []) {
            $summary = $proposal['reasoning_summary'] ?? null;
        }

        if (is_string($summary) && trim($summary) !== '') {
            return [[
                'label' => 'Reasoning',
                'text' => trim($summary),
            ]];
        }

        if (! is_array($summary)) {
            return [];
        }

        $labels = [
            'primary_category' => 'Primary Category',
            'relationships' => 'Relationships',
            'recipient_types' => 'Recipient types',
            'occasions' => 'Occasions',
            'interests' => 'Interests',
            'professions' => 'Professions',
            'gift_types' => 'Gift types',
        ];

        $blocks = [];

        foreach ($summary as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $label = is_string($key) && array_key_exists($key, $labels)
                ? $labels[$key]
                : (is_string($key) ? str($key)->replace('_', ' ')->headline()->toString() : 'Reasoning');

            $blocks[] = [
                'label' => $label,
                'text' => trim($value),
            ];
        }

        return $blocks;
    }

    /**
     * @param  list<array{label: string, text: string}>  $blocks
     */
    private function reasoningText(array $blocks): ?string
    {
        $text = collect($blocks)
            ->pluck('text')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->implode(' ');

        return $text !== '' ? $text : null;
    }

    private function primaryLink(Product $product): ?AffiliateLink
    {
        $link = $product->affiliateLinks->firstWhere('is_primary', true)
            ?? $product->affiliateLinks->first();

        return $link instanceof AffiliateLink ? $link : null;
    }

    private function imageUrl(Product $product): ?string
    {
        $image = $product->images->firstWhere('is_primary', true)
            ?? $product->images->first();

        return $image instanceof ProductImage ? $image->url() : null;
    }

    private function priceDisplay(Product $product): ?string
    {
        if ($product->price_amount === null) {
            return null;
        }

        $currency = strtoupper((string) ($product->price_currency ?: 'INR'));
        $value = (float) $product->price_amount;

        if ($currency === 'INR') {
            return '₹'.number_format($value, $value == floor($value) ? 0 : 2);
        }

        return $currency.' '.number_format($value, 2);
    }

    private function availabilityLabel(?string $availability): string
    {
        return match ($availability) {
            'in_stock' => 'In stock',
            'out_of_stock' => 'Out of stock',
            'unavailable' => 'Unavailable',
            'unknown', null, '' => 'Unknown',
            default => ucwords(str_replace('_', ' ', $availability)),
        };
    }

    private function score(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * @return list<int>
     */
    private function idList(mixed $raw): array
    {
        if (! is_array($raw)) {
            $id = $this->nullableId($raw);

            return $id !== null ? [$id] : [];
        }

        $ids = [];

        foreach ($raw as $value) {
            $id = $this->nullableId($value);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function nullableId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
