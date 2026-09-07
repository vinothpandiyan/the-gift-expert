<?php

namespace App\Actions\Discovery;

use App\Actions\Taxonomy\ResolveApplicableTaxonomyOptionsAction;
use App\DiscoveryListing\DiscoveryFilterOption;
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
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ResolveDiscoveryFilterOptionsAction
{
    public function __construct(
        private ResolveApplicableTaxonomyOptionsAction $resolveApplicable,
        private CountDiscoveryFilterFacetsAction $countFacets,
    ) {}

    /**
     * Semantically applicable, non-zero discovery filter options with counts.
     *
     * Selected options that are still valid but have zero matching products are
     * kept with count 0 so an active constraint never disappears invisibly.
     * Semantically invalid selections must be cleared first via
     * NormalizeDiscoveryFilterStateAction.
     *
     * Category options include both active roots and children. Counts are per
     * attached category ID (no parent roll-up). `parentId` is provided so the
     * listing UI can nest children under visible parents without extra queries.
     *
     * @return array<string, Collection<int, DiscoveryFilterOption>>
     */
    public function execute(
        DiscoveryListingContext $listing,
        DiscoveryListingQueryState $state,
    ): array {
        $state = $state->scopedTo($listing);
        $options = [];

        foreach ($listing->availableDimensions as $dimension) {
            $records = $this->loadCandidates($listing, $dimension);

            if ($records->isEmpty()) {
                $options[$dimension] = collect();

                continue;
            }

            $taxonomy = TaxonomyDimension::tryFromListingDimension($dimension);

            if ($taxonomy !== null) {
                $applicableIds = $this->resolveApplicable->execute(
                    $listing,
                    $taxonomy,
                    $records->all(),
                    $state->withoutUserDimension($dimension),
                );
                $records = $records
                    ->filter(fn (Model $record): bool => in_array((int) $record->id, $applicableIds, true))
                    ->values();
            }

            if ($records->isEmpty()) {
                $options[$dimension] = collect();

                continue;
            }

            $candidateIds = $records->map(fn (Model $record): int => (int) $record->id)->all();
            $counts = $this->countFacets->execute($listing, $state, $dimension, $candidateIds);
            $selected = $state->slugsFor($dimension);

            $options[$dimension] = $records
                ->map(fn (Model $record): DiscoveryFilterOption => $this->toOption(
                    $dimension,
                    $record,
                    $counts,
                    $selected,
                ))
                ->filter(fn (DiscoveryFilterOption $option): bool => $option->count > 0 || $option->selected)
                ->values();
        }

        return $options;
    }

    /**
     * @return Collection<int, Model>
     */
    private function loadCandidates(DiscoveryListingContext $listing, string $dimension): Collection
    {
        if ($dimension === 'budget') {
            return BudgetRange::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name', 'slug']);
        }

        if ($dimension === 'category') {
            return Category::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'full_path', 'parent_id']);
        }

        $query = match ($dimension) {
            'occasion' => Occasion::query(),
            'relationship' => Relationship::query(),
            'recipient' => RecipientType::query(),
            'interest' => Interest::query(),
            'profession' => Profession::query(),
            'gift_type' => GiftType::query(),
            default => null,
        };

        if (! $query instanceof EloquentBuilder) {
            return collect();
        }

        $records = $this->activeTaxonomy($query);

        if ($dimension === 'interest' && $listing->hiddenInterestIds !== []) {
            $hidden = $listing->hiddenInterestIds;
            $records = $records
                ->reject(fn (Model $record): bool => in_array((int) $record->id, $hidden, true))
                ->values();
        }

        return $records;
    }

    /**
     * @return Collection<int, Model>
     */
    private function activeTaxonomy(EloquentBuilder $query): Collection
    {
        return $query
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    /**
     * @param  array<int, int>  $counts
     * @param  list<string>  $selected
     */
    private function toOption(string $dimension, Model $record, array $counts, array $selected): DiscoveryFilterOption
    {
        $id = (int) $record->id;
        $slug = $dimension === 'category'
            ? (string) $record->full_path
            : (string) $record->slug;

        return new DiscoveryFilterOption(
            id: $id,
            label: (string) $record->name,
            slug: $slug,
            count: $counts[$id] ?? 0,
            selected: in_array($slug, $selected, true),
            parentId: $dimension === 'category' && $record->parent_id !== null
                ? (int) $record->parent_id
                : null,
        );
    }
}
