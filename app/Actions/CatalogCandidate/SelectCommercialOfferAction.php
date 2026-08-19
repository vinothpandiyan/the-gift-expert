<?php

namespace App\Actions\CatalogCandidate;

use App\CommercialSourcing\CommercialOfferSelection;
use App\CommercialSourcing\CommercialSourcingMerchants;
use App\CommercialSourcing\ExtractCommercialExternalProductId;
use App\CommercialSourcing\SourcedMerchantOffer;
use App\Enums\CommercialExternalIdSource;
use App\Models\CatalogCandidate;

class SelectCommercialOfferAction
{
    public function __construct(
        private CommercialSourcingMerchants $merchants,
        private ExtractCommercialExternalProductId $extractExternalProductId,
    ) {}

    /**
     * @param  list<SourcedMerchantOffer>  $offers
     */
    public function execute(CatalogCandidate $candidate, array $offers): CommercialOfferSelection
    {
        $scored = [];

        foreach ($offers as $offer) {
            [$score, $breakdown] = $this->score($candidate, $offer);
            $scored[] = [
                'offer' => $offer->withRankScore($score),
                'breakdown' => $breakdown,
            ];
        }

        usort($scored, function (array $left, array $right): int {
            $scoreCmp = $right['offer']->rankScore <=> $left['offer']->rankScore;

            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            $sourceCmp = $this->sourceRank($right['offer']->externalIdSource) <=> $this->sourceRank($left['offer']->externalIdSource);

            if ($sourceCmp !== 0) {
                return $sourceCmp;
            }

            $priorityCmp = $this->priority($right['offer']->merchantSlug) <=> $this->priority($left['offer']->merchantSlug);

            if ($priorityCmp !== 0) {
                return $priorityCmp;
            }

            $slugCmp = $left['offer']->merchantSlug <=> $right['offer']->merchantSlug;

            if ($slugCmp !== 0) {
                return $slugCmp;
            }

            return $left['offer']->normalizedUrl <=> $right['offer']->normalizedUrl;
        });

        $ordered = array_map(fn (array $row): SourcedMerchantOffer => $row['offer'], $scored);
        $selected = $ordered[0] ?? null;
        $rankBreakdown = $selected === null ? [] : ($scored[0]['breakdown'] ?? []);

        return new CommercialOfferSelection(
            candidate: $candidate,
            selected: $selected,
            ordered: $ordered,
            rankBreakdown: $rankBreakdown,
        );
    }

    /**
     * @return array{0: int, 1: array<string, int>}
     */
    private function score(CatalogCandidate $candidate, SourcedMerchantOffer $offer): array
    {
        $config = $this->merchants->configForSlug($offer->merchantSlug) ?? [];
        $identity = $this->extractExternalProductId->execute($offer->merchantSlug, $offer->sourceUrl);
        $priority = (int) ($config['priority'] ?? 0);
        $affiliateEnabled = ($config['affiliate_enabled'] ?? false) === true;
        $affiliateStrategy = (string) ($config['affiliate_strategy'] ?? 'manual');

        $external = match ($offer->externalIdSource) {
            CommercialExternalIdSource::Extracted => 1000,
            CommercialExternalIdSource::UrlFingerprint => 400,
            CommercialExternalIdSource::None => 0,
        };

        $unstable = $identity->unstableIdentity ? -5000 : 0;
        $affiliate = $affiliateEnabled ? 200 : 0;
        $strategy = $affiliateStrategy !== 'manual' ? 100 : 0;
        $price = $offer->priceAmount !== null ? 50 : 0;
        $relevance = $this->titleRelevance($candidate->title, $offer->title);

        $breakdown = [
            'merchant_priority' => $priority,
            'external_id' => $external,
            'unstable_identity' => $unstable,
            'affiliate_enabled' => $affiliate,
            'affiliate_strategy' => $strategy,
            'price_present' => $price,
            'title_relevance' => $relevance,
        ];

        return [array_sum($breakdown), $breakdown];
    }

    private function titleRelevance(string $candidateTitle, string $offerTitle): int
    {
        $needle = mb_strtolower(trim($candidateTitle), 'UTF-8');
        $haystack = mb_strtolower(trim($offerTitle), 'UTF-8');

        if ($needle === '' || $haystack === '') {
            return 0;
        }

        if (str_contains($haystack, $needle)) {
            return 80;
        }

        $words = preg_split('/\s+/u', $needle) ?: [];
        $matched = 0;
        $considered = 0;

        foreach ($words as $word) {
            if (mb_strlen($word) < 3) {
                continue;
            }

            $considered++;

            if (str_contains($haystack, $word)) {
                $matched++;
            }
        }

        if ($considered === 0 || $matched === 0) {
            return 0;
        }

        return (int) floor(40 * ($matched / $considered));
    }

    private function sourceRank(CommercialExternalIdSource $source): int
    {
        return match ($source) {
            CommercialExternalIdSource::Extracted => 3,
            CommercialExternalIdSource::UrlFingerprint => 2,
            CommercialExternalIdSource::None => 1,
        };
    }

    private function priority(string $slug): int
    {
        $config = $this->merchants->configForSlug($slug);

        return (int) ($config['priority'] ?? 0);
    }
}
