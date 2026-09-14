<?php

namespace App\Actions\GapSourcing;

use App\Enums\GapSourcingKind;
use App\Enums\GapSourcingOverlap;
use App\Enums\GapSourcingTriage;
use App\GapSourcing\CatalogConceptMatch;
use App\GapSourcing\GapSourcingCandidate;
use App\GapSourcing\GapSourcingGap;
use App\GapSourcing\GapSourcingShortlistItem;
use App\GapSourcing\GapSourcingShortlistReport;
use App\Models\Product;
use InvalidArgumentException;

class BuildGapSourcingShortlistAction
{
    public function __construct(
        private ResolveGenuineCatalogGapsAction $resolveGaps,
        private CompareGapSourcingCandidateToCatalogAction $compare,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    public function execute(array $payloads, bool $includeSecondary = false): GapSourcingShortlistReport
    {
        $productCountBefore = Product::query()->withTrashed()->count();
        $gaps = $this->indexedGaps($this->resolveGaps->execute($includeSecondary));
        $catalog = $this->compare->catalog();
        $items = [];
        $duplicateIds = [];
        $seen = [];

        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                throw new InvalidArgumentException('Each gap sourcing candidate must be an object.');
            }

            $candidate = GapSourcingCandidate::fromArray($payload);
            $identity = $candidate->identityKey();

            if (isset($seen[$identity])) {
                $duplicateIds[] = $candidate->id;
                $items[] = $this->rejected(
                    $candidate,
                    new CatalogConceptMatch(
                        overlap: GapSourcingOverlap::GenuineNewConcept,
                        productId: null,
                        title: null,
                        concept: null,
                        status: null,
                        decision: null,
                        priceAmount: null,
                        giftIntents: [],
                        summary: 'Duplicate of '.$seen[$identity],
                    ),
                    'duplicate_candidate',
                );

                continue;
            }

            $seen[$identity] = $candidate->id;
            $nearest = $this->compare->execute($candidate, $catalog);
            $items[] = $this->triage($candidate, $nearest, $gaps[$candidate->targetGap] ?? null);
        }

        $productCountAfter = Product::query()->withTrashed()->count();

        return new GapSourcingShortlistReport(
            gaps: array_values($gaps),
            items: $items,
            duplicateIds: $duplicateIds,
            productCountBefore: $productCountBefore,
            productCountAfter: $productCountAfter,
            wishlistActions: 0,
            publishedMutations: 0,
            archivedMutations: 0,
        );
    }

    /**
     * @param  list<GapSourcingGap>  $gaps
     * @return array<string, GapSourcingGap>
     */
    private function indexedGaps(array $gaps): array
    {
        $indexed = [];

        foreach ($gaps as $gap) {
            $indexed[$gap->key] = $gap;
        }

        return $indexed;
    }

    /**
     * @param  array<string, GapSourcingGap>  $gaps
     */
    private function triage(
        GapSourcingCandidate $candidate,
        CatalogConceptMatch $nearest,
        ?GapSourcingGap $gap,
    ): GapSourcingShortlistItem {
        $rejectReason = $this->rejectReason($candidate, $nearest, $gap);

        if ($rejectReason !== null) {
            return $this->rejected($candidate, $nearest, $rejectReason);
        }

        return new GapSourcingShortlistItem(
            candidate: $candidate,
            nearest: $nearest,
            triage: GapSourcingTriage::Shortlist,
            gapContribution: $this->gapContribution($candidate, $nearest, $gap),
            priceBand: $this->priceBand($candidate->priceAmount),
            wishlistEligible: $this->wishlistEligible($candidate),
            rejectReason: '',
        );
    }

    private function rejectReason(
        GapSourcingCandidate $candidate,
        CatalogConceptMatch $nearest,
        ?GapSourcingGap $gap,
    ): ?string {
        if ($gap === null) {
            return 'unknown_gap';
        }

        if ($gap->kind === GapSourcingKind::Covered) {
            return 'published_coverage_already_exists';
        }

        if ($gap->kind === GapSourcingKind::Publication) {
            return 'publication_backlog_not_a_sourcing_priority';
        }

        if ($nearest->overlap === GapSourcingOverlap::ExactAsinAlreadyInCatalog) {
            return 'exact_asin_already_in_catalog';
        }

        if (
            $nearest->overlap === GapSourcingOverlap::ExactConceptAlreadyStrong
            && $candidate->differentiated !== true
        ) {
            return 'unnecessary_concept_overlap';
        }

        $blocked = (array) config('gap_sourcing.blocked_generic_concepts', []);

        if (in_array($candidate->concept, $blocked, true) && $candidate->differentiated !== true) {
            return 'generic_undifferentiated_concept';
        }

        if ($this->isUnavailable($candidate->availability)) {
            return 'unavailable';
        }

        if ($candidate->targetGap === 'father') {
            $reason = strtolower(trim((string) $candidate->fatherSpecificReason));

            if ($reason === '' || in_array($reason, [
                'a man could use this',
                'a man could use this.',
                'a man could use it',
                'a man could use it.',
            ], true)) {
                return 'not_father_specific';
            }
        }

        if ($candidate->targetGap === 'experience_gifts') {
            if ($candidate->isGiftCard) {
                return 'gift_card_is_not_an_experience';
            }

            if ($candidate->experienceType === null || $candidate->redemptionMethod === null) {
                return 'unclear_experience_redemption';
            }
        }

        if ($candidate->targetGap === 'eco_conscious' && $candidate->sustainabilityEvidence === null) {
            return 'insufficient_sustainability_evidence';
        }

        if ($candidate->giftPotential === 'low') {
            return 'weak_gift_potential';
        }

        if (
            $candidate->rating !== null
            && $candidate->rating < 4.0
            && $candidate->reviewCount !== null
            && $candidate->reviewCount >= 10
        ) {
            return 'weak_merchant_confidence';
        }

        return null;
    }

    private function gapContribution(
        GapSourcingCandidate $candidate,
        CatalogConceptMatch $nearest,
        ?GapSourcingGap $gap,
    ): string {
        if ($gap === null || $gap->kind !== GapSourcingKind::Inventory) {
            return 'none';
        }

        if ($nearest->overlap === GapSourcingOverlap::GenuineNewConcept) {
            return $gap->priority <= 2 ? 'high' : 'medium';
        }

        if (in_array($nearest->overlap, [
            GapSourcingOverlap::ConceptExistsDifferentBudget,
            GapSourcingOverlap::ConceptExistsDifferentIntent,
            GapSourcingOverlap::ConceptExistsButWeak,
        ], true)) {
            return 'medium';
        }

        return 'low';
    }

    private function wishlistEligible(GapSourcingCandidate $candidate): bool
    {
        $merchant = strtolower($candidate->merchant);
        $allowed = array_map('strtolower', (array) config('gap_sourcing.wishlist_eligible_merchants', ['amazon-in']));
        $host = parse_url($candidate->sourceUrl, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : '';
        $amazonHost = str_ends_with($host, 'amazon.in');

        return (in_array($merchant, $allowed, true) || $amazonHost)
            && is_string($candidate->externalId)
            && $candidate->externalId !== ''
            && ! $this->isUnavailable($candidate->availability);
    }

    private function isUnavailable(?string $availability): bool
    {
        if ($availability === null) {
            return false;
        }

        $normalized = strtolower($availability);

        return str_contains($normalized, 'unavailable')
            || str_contains($normalized, 'out of stock');
    }

    private function priceBand(?float $amount): string
    {
        if ($amount === null) {
            return 'unknown';
        }

        foreach ((array) config('gap_sourcing.price_bands', []) as $band) {
            if (! is_array($band)) {
                continue;
            }

            $min = isset($band['min']) ? (float) $band['min'] : 0.0;
            $max = $band['max'] ?? null;

            if ($amount < $min) {
                continue;
            }

            if ($max !== null && $amount > (float) $max) {
                continue;
            }

            return (string) ($band['label'] ?? $band['slug'] ?? 'unknown');
        }

        return 'unknown';
    }

    private function rejected(
        GapSourcingCandidate $candidate,
        CatalogConceptMatch $nearest,
        string $reason,
    ): GapSourcingShortlistItem {
        return new GapSourcingShortlistItem(
            candidate: $candidate,
            nearest: $nearest,
            triage: GapSourcingTriage::Reject,
            gapContribution: 'none',
            priceBand: $this->priceBand($candidate->priceAmount),
            wishlistEligible: false,
            rejectReason: $reason,
        );
    }
}
