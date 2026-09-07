<?php

namespace App\Actions\Discovery;

use App\Actions\Taxonomy\ResolveApplicableTaxonomyOptionsAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Enums\TaxonomyDimension;
use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use Illuminate\Database\Eloquent\Model;

class NormalizeDiscoveryFilterStateAction
{
    /**
     * Semantic cleanup order when loading from a URL (keep earlier dimensions).
     *
     * @var list<string>
     */
    private const SEMANTIC_ORDER = [
        'relationship',
        'recipient',
        'profession',
        'interest',
        'gift_type',
        'category',
        'occasion',
    ];

    public function __construct(
        private ResolveApplicableTaxonomyOptionsAction $resolveApplicable,
    ) {}

    /**
     * Drop unknown/inactive slugs and semantically invalid selected taxonomy values.
     *
     * `$preferredDimension` is last-write-wins: a newly toggled dimension is kept
     * (if valid against the page) and conflicting values in other dimensions are
     * removed. URL loads omit it and use {@see SEMANTIC_ORDER}.
     */
    public function execute(
        DiscoveryListingContext $listing,
        DiscoveryListingQueryState $state,
        ?string $preferredDimension = null,
    ): DiscoveryListingQueryState {
        $state = $state->scopedTo($listing);
        $working = $state->withoutUserTaxonomy()->withSlugsFor(
            'budget',
            $this->activeBudgetSlug($state),
        );

        foreach ($this->dimensionOrder($preferredDimension) as $dimension) {
            if (! $listing->allows($dimension)) {
                continue;
            }

            $selected = $state->slugsFor($dimension);

            if ($selected === []) {
                continue;
            }

            $records = $this->activeRecords($dimension, $selected);

            if ($records === []) {
                continue;
            }

            $taxonomy = TaxonomyDimension::tryFromListingDimension($dimension);

            if ($taxonomy === null) {
                continue;
            }

            $ids = array_map(fn (Model $record): int => (int) $record->id, $records);
            $applicableIds = $this->resolveApplicable->execute($listing, $taxonomy, $ids, $working);
            $keptSlugs = [];

            foreach ($records as $record) {
                if (! in_array((int) $record->id, $applicableIds, true)) {
                    continue;
                }

                $keptSlugs[] = $dimension === 'category'
                    ? (string) $record->full_path
                    : (string) $record->slug;
            }

            $working = $working->withSlugsFor($dimension, $keptSlugs);
        }

        return $working;
    }

    /**
     * @return list<string>
     */
    private function dimensionOrder(?string $preferredDimension): array
    {
        if ($preferredDimension === null || ! in_array($preferredDimension, self::SEMANTIC_ORDER, true)) {
            return self::SEMANTIC_ORDER;
        }

        return array_values(array_unique([$preferredDimension, ...self::SEMANTIC_ORDER]));
    }

    /**
     * @param  list<string>  $slugs
     * @return list<Model>
     */
    private function activeRecords(string $dimension, array $slugs): array
    {
        if ($dimension === 'category') {
            return Category::query()
                ->whereIn('full_path', $slugs)
                ->where('is_active', true)
                ->get()
                ->all();
        }

        $model = match ($dimension) {
            'occasion' => Occasion::class,
            'relationship' => Relationship::class,
            'recipient' => RecipientType::class,
            'interest' => Interest::class,
            'profession' => Profession::class,
            'gift_type' => GiftType::class,
            default => null,
        };

        if ($model === null) {
            return [];
        }

        return $model::query()
            ->whereIn('slug', $slugs)
            ->where('is_active', true)
            ->get()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function activeBudgetSlug(DiscoveryListingQueryState $state): array
    {
        if ($state->budgetSlug === null) {
            return [];
        }

        $exists = BudgetRange::query()
            ->where('slug', $state->budgetSlug)
            ->where('is_active', true)
            ->exists();

        return $exists ? [$state->budgetSlug] : [];
    }
}
