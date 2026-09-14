<?php

namespace App\Actions\GapSourcing;

use App\Enums\GapSourcingOverlap;
use App\Enums\ProductCurationAuditOutcome;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductStatus;
use App\GapSourcing\CatalogConceptMatch;
use App\GapSourcing\GapSourcingCandidate;
use App\Models\Product;
use App\Models\ProductCurationAudit;

class CompareGapSourcingCandidateToCatalogAction
{
    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        $products = Product::query()
            ->with(['affiliateLinks', 'currentCurationDecision'])
            ->orderBy('id')
            ->get();

        $audits = ProductCurationAudit::query()
            ->whereIn('product_id', $products->modelKeys())
            ->where('outcome', ProductCurationAuditOutcome::Completed)
            ->orderByDesc('id')
            ->get()
            ->unique('product_id')
            ->keyBy('product_id');

        $rows = [];

        foreach ($products as $product) {
            $audit = $audits->get($product->id);
            $decision = $product->currentCurationDecision;

            $rows[] = [
                'product_id' => (int) $product->id,
                'title' => (string) $product->name,
                'status' => $product->status?->value,
                'decision' => $decision?->decision instanceof ProductCurationDecision
                    ? $decision->decision->value
                    : null,
                'price_amount' => $product->price_amount !== null ? (float) $product->price_amount : null,
                'asins' => $product->affiliateLinks
                    ->pluck('external_product_id')
                    ->filter(fn (mixed $asin): bool => is_string($asin) && $asin !== '')
                    ->map(fn (string $asin): string => strtoupper($asin))
                    ->values()
                    ->all(),
                'concept' => is_string($audit?->concept_key) ? $audit->concept_key : null,
                'gift_intents' => is_array($audit?->gift_intents) ? $audit->gift_intents : [],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $catalog
     */
    public function execute(GapSourcingCandidate $candidate, array $catalog): CatalogConceptMatch
    {
        $asin = is_string($candidate->externalId) ? strtoupper($candidate->externalId) : null;

        if ($asin !== null) {
            foreach ($catalog as $row) {
                $asins = is_array($row['asins'] ?? null) ? $row['asins'] : [];

                if (in_array($asin, $asins, true)) {
                    return $this->match(
                        GapSourcingOverlap::ExactAsinAlreadyInCatalog,
                        $row,
                        'Exact ASIN already exists in the catalog.',
                    );
                }
            }
        }

        $conceptMatches = array_values(array_filter(
            $catalog,
            fn (array $row): bool => is_string($row['concept'] ?? null)
                && $row['concept'] !== ''
                && $row['concept'] === $candidate->concept,
        ));

        if ($conceptMatches === []) {
            return new CatalogConceptMatch(
                overlap: GapSourcingOverlap::GenuineNewConcept,
                productId: null,
                title: null,
                concept: null,
                status: null,
                decision: null,
                priceAmount: null,
                giftIntents: [],
                summary: 'No existing catalog concept match.',
            );
        }

        $strong = $this->firstWhere($conceptMatches, fn (array $row): bool => $this->isStrong($row));
        $weak = $this->firstWhere($conceptMatches, fn (array $row): bool => $this->isWeak($row));
        $differentBudget = $this->firstWhere(
            $conceptMatches,
            fn (array $row): bool => $this->priceBand((float) ($row['price_amount'] ?? 0)) !== $this->priceBand($candidate->priceAmount),
        );
        $differentIntent = $this->firstWhere(
            $conceptMatches,
            fn (array $row): bool => $this->intents((array) ($row['gift_intents'] ?? [])) !== []
                && array_intersect($this->intents($candidate->giftIntents), $this->intents((array) $row['gift_intents'])) === [],
        );

        if ($strong !== null && $differentBudget === null && $differentIntent === null) {
            return $this->match($this->strongOverlap($candidate, $strong), $strong, 'Same concept already exists as strong catalog inventory.');
        }

        if ($differentIntent !== null) {
            return $this->match(
                GapSourcingOverlap::ConceptExistsDifferentIntent,
                $differentIntent,
                'Same concept exists for a different GiftIntent.',
            );
        }

        if ($differentBudget !== null) {
            return $this->match(
                GapSourcingOverlap::ConceptExistsDifferentBudget,
                $differentBudget,
                'Same concept exists in a different budget band.',
            );
        }

        if ($weak !== null) {
            return $this->match(
                GapSourcingOverlap::ConceptExistsButWeak,
                $weak,
                'Same concept exists but the current Product is weak.',
            );
        }

        $fallback = $strong ?? $conceptMatches[0];

        return $this->match(
            $this->strongOverlap($candidate, $fallback),
            $fallback,
            'Nearest existing catalog concept.',
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function match(GapSourcingOverlap $overlap, array $row, string $summary): CatalogConceptMatch
    {
        return new CatalogConceptMatch(
            overlap: $overlap,
            productId: isset($row['product_id']) ? (int) $row['product_id'] : null,
            title: is_string($row['title'] ?? null) ? $row['title'] : null,
            concept: is_string($row['concept'] ?? null) ? $row['concept'] : null,
            status: is_string($row['status'] ?? null) ? $row['status'] : null,
            decision: is_string($row['decision'] ?? null) ? $row['decision'] : null,
            priceAmount: isset($row['price_amount']) && is_numeric($row['price_amount']) ? (float) $row['price_amount'] : null,
            giftIntents: $this->intents((array) ($row['gift_intents'] ?? [])),
            summary: $summary,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function strongOverlap(GapSourcingCandidate $candidate, array $row): GapSourcingOverlap
    {
        if ($this->isWeak($row)) {
            return GapSourcingOverlap::ConceptExistsButWeak;
        }

        if ($this->priceBand((float) ($row['price_amount'] ?? 0)) !== $this->priceBand($candidate->priceAmount)) {
            return GapSourcingOverlap::ConceptExistsDifferentBudget;
        }

        $existingIntents = $this->intents((array) ($row['gift_intents'] ?? []));
        $incomingIntents = $this->intents($candidate->giftIntents);

        if ($existingIntents !== [] && $incomingIntents !== [] && array_intersect($incomingIntents, $existingIntents) === []) {
            return GapSourcingOverlap::ConceptExistsDifferentIntent;
        }

        return GapSourcingOverlap::ExactConceptAlreadyStrong;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isStrong(array $row): bool
    {
        $status = $row['status'] ?? null;
        $decision = $row['decision'] ?? null;
        $keepFamily = (array) config('gap_sourcing.keep_family_decisions', ['keep', 'feature', 'keep_niche']);

        return $status === ProductStatus::Published->value
            || in_array($decision, $keepFamily, true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isWeak(array $row): bool
    {
        return ($row['decision'] ?? null) === ProductCurationDecision::RemoveCandidate->value;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): bool  $predicate
     * @return array<string, mixed>|null
     */
    private function firstWhere(array $rows, callable $predicate): ?array
    {
        foreach ($rows as $row) {
            if ($predicate($row)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $intents
     * @return list<string>
     */
    private function intents(array $intents): array
    {
        $normalized = [];

        foreach ($intents as $intent) {
            if (! is_string($intent) || trim($intent) === '') {
                continue;
            }

            $normalized[] = strtolower(trim($intent));
        }

        return array_values(array_unique($normalized));
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

            return (string) ($band['slug'] ?? 'unknown');
        }

        return 'unknown';
    }
}
