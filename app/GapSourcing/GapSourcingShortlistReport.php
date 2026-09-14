<?php

namespace App\GapSourcing;

use App\Enums\GapSourcingKind;
use App\Enums\GapSourcingTriage;

readonly class GapSourcingShortlistReport
{
    /**
     * @param  list<GapSourcingGap>  $gaps
     * @param  list<GapSourcingShortlistItem>  $items
     * @param  list<string>  $duplicateIds
     */
    public function __construct(
        public array $gaps,
        public array $items,
        public array $duplicateIds,
        public int $productCountBefore,
        public int $productCountAfter,
        public int $wishlistActions,
        public int $publishedMutations,
        public int $archivedMutations,
    ) {}

    /**
     * @return list<GapSourcingShortlistItem>
     */
    public function shortlisted(): array
    {
        return array_values(array_filter(
            $this->items,
            fn (GapSourcingShortlistItem $item): bool => $item->triage === GapSourcingTriage::Shortlist,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function coverageMatrix(): array
    {
        $rows = [];

        foreach ($this->gaps as $gap) {
            $strong = count(array_filter(
                $this->shortlisted(),
                fn (GapSourcingShortlistItem $item): bool => $item->candidate->targetGap === $gap->key
                    && $item->candidate->giftPotential === 'high',
            ));

            $remaining = match ($gap->kind) {
                GapSourcingKind::Covered => 'none — published coverage exists',
                GapSourcingKind::Publication => 'none — publish-ready drafts already cover this',
                default => $strong > 0
                    ? ($gap->key === 'experience_gifts' && $this->intakeReadyCount($gap->key) === 0
                        ? 'intake path still missing'
                        : 'review shortlist then intake')
                    : 'still needs credible candidates',
            };

            $rows[] = [
                'gap' => $gap->label,
                'before' => $gap->publishedCount,
                'kind' => $gap->kind->value,
                'strong_candidates' => $strong,
                'shortlisted' => $this->shortlistCount($gap->key),
                'remaining_need' => $remaining,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'gaps' => array_map(fn (GapSourcingGap $gap): array => $gap->toArray(), $this->gaps),
            'coverage_matrix' => $this->coverageMatrix(),
            'discovered' => count($this->items),
            'deduplicated' => $this->duplicateIds,
            'shortlisted' => array_map(
                fn (GapSourcingShortlistItem $item): array => $item->toArray(),
                $this->shortlisted(),
            ),
            'rejected' => array_map(
                fn (GapSourcingShortlistItem $item): array => $item->toArray(),
                array_values(array_filter(
                    $this->items,
                    fn (GapSourcingShortlistItem $item): bool => $item->triage === GapSourcingTriage::Reject,
                )),
            ),
            'catalog_mutations' => [
                'products_before' => $this->productCountBefore,
                'products_after' => $this->productCountAfter,
                'wishlist_actions' => $this->wishlistActions,
                'published_mutations' => $this->publishedMutations,
                'archived_mutations' => $this->archivedMutations,
            ],
        ];
    }

    private function shortlistCount(string $gapKey): int
    {
        return count(array_filter(
            $this->shortlisted(),
            fn (GapSourcingShortlistItem $item): bool => $item->candidate->targetGap === $gapKey,
        ));
    }

    private function intakeReadyCount(string $gapKey): int
    {
        return count(array_filter(
            $this->shortlisted(),
            fn (GapSourcingShortlistItem $item): bool => $item->candidate->targetGap === $gapKey
                && $item->wishlistEligible,
        ));
    }
}
