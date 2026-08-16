<?php

namespace App\Actions\Recommendation;

use App\Actions\Product\QueryPublishedProductsByFiltersAction;
use App\Models\GiftType;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\RecommendationResult;
use App\Models\RecommendationSession;
use App\Models\Relationship;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class GenerateRecommendationsAction
{
    /**
     * @param  array{
     *     occasion_id?: int|null,
     *     budget_range_id?: int|null,
     *     relationship_id?: int|null,
     *     recipient_type_id?: int|null,
     *     profession_id?: int|null,
     *     gift_type_id?: int|null,
     *     interest_ids?: list<int>,
     *     ip_hash?: string|null,
     *     user_agent?: string|null,
     *     referrer_url?: string|null,
     * }  $input
     */
    public function execute(array $input): RecommendationSession
    {
        $maxInterests = (int) config('gift_recommendations.max_interests');
        $interestIds = collect($input['interest_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->take($maxInterests)
            ->all();

        $session = RecommendationSession::query()->create([
            'occasion_id' => $input['occasion_id'] ?? null,
            'budget_range_id' => $input['budget_range_id'] ?? null,
            'relationship_id' => $input['relationship_id'] ?? null,
            'recipient_type_id' => $input['recipient_type_id'] ?? null,
            'profession_id' => $input['profession_id'] ?? null,
            'gift_type_id' => $input['gift_type_id'] ?? null,
            'ip_hash' => $input['ip_hash'] ?? null,
            'user_agent' => $input['user_agent'] ?? null,
            'referrer_url' => $input['referrer_url'] ?? null,
        ]);

        if ($interestIds !== []) {
            $session->interests()->attach($interestIds);
        }

        $session->load([
            'occasion',
            'budgetRange',
            'relationship',
            'recipientType',
            'profession',
            'giftType',
            'interests',
        ]);

        $ranked = $this->rankCandidates($session, $interestIds);

        foreach ($ranked as $index => $candidate) {
            RecommendationResult::query()->create([
                'recommendation_session_id' => $session->id,
                'product_id' => $candidate['product']->id,
                'score' => $candidate['score'],
                'rank' => $index + 1,
                'score_breakdown' => $candidate['breakdown'],
                'explanation' => $candidate['explanation'],
            ]);
        }

        return $session->fresh(['results', 'interests']);
    }

    /**
     * @param  list<int>  $interestIds
     * @return list<array{product: Product, score: float, breakdown: array<string, float|int>, explanation: string}>
     */
    private function rankCandidates(RecommendationSession $session, array $interestIds): array
    {
        $weights = config('gift_recommendations.weights');
        $topN = (int) config('gift_recommendations.top_n');

        $candidates = $this->eligibleProducts($session, $interestIds)
            ->with([
                'occasions:id,name',
                'relationships:id,name',
                'recipientTypes:id,name',
                'interests:id,name',
                'professions:id,name',
                'giftTypes:id,name',
            ])
            ->get();

        $scored = $candidates->map(function (Product $product) use ($session, $interestIds, $weights): array {
            $breakdown = $this->scoreProduct($product, $session, $interestIds, $weights);
            $score = (float) ($breakdown['total'] ?? 0);

            return [
                'product' => $product,
                'score' => $score,
                'breakdown' => $breakdown,
                'explanation' => $this->buildExplanation($breakdown, $session, $product, $interestIds),
            ];
        });

        return $this->sortCandidates($scored)
            ->take($topN)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $interestIds
     */
    private function eligibleProducts(RecommendationSession $session, array $interestIds): Builder
    {
        $filters = [
            'budget_range_id' => $session->budget_range_id,
        ];

        if (config('gift_recommendations.optional_dimensions_filter_strict')) {
            $filters['relationship_id'] = $session->relationship_id;
            $filters['recipient_type_id'] = $session->recipient_type_id;
            $filters['profession_id'] = $session->profession_id;
            $filters['gift_type_id'] = $session->gift_type_id;
            $filters['interest_ids'] = $interestIds;
        }

        return app(QueryPublishedProductsByFiltersAction::class)->execute(
            $filters,
            requireActiveAffiliate: true,
            allowUnfiltered: true,
            matchAllInterests: false,
        );
    }

    /**
     * @param  list<int>  $interestIds
     * @param  array<string, int>  $weights
     * @return array<string, float|int>
     */
    private function scoreProduct(Product $product, RecommendationSession $session, array $interestIds, array $weights): array
    {
        $breakdown = [];

        if ($session->occasion_id !== null && $product->occasions->contains('id', $session->occasion_id)) {
            $breakdown['occasion_match'] = $weights['occasion_match'];
        }

        if ($session->relationship_id !== null && $product->relationships->contains('id', $session->relationship_id)) {
            $breakdown['relationship_match'] = $weights['relationship_match'];
        }

        if ($session->recipient_type_id !== null && $product->recipientTypes->contains('id', $session->recipient_type_id)) {
            $breakdown['recipient_type_match'] = $weights['recipient_type_match'];
        }

        if ($interestIds !== []) {
            $overlap = $product->interests->whereIn('id', $interestIds)->count();

            if ($overlap > 0) {
                $interestScore = min(
                    $overlap * $weights['interest_match'],
                    $weights['interest_match_max'],
                );
                $breakdown['interest_match'] = $interestScore;
            }
        }

        if ($session->profession_id !== null && $product->professions->contains('id', $session->profession_id)) {
            $breakdown['profession_match'] = $weights['profession_match'];
        }

        if ($session->gift_type_id !== null && $product->giftTypes->contains('id', $session->gift_type_id)) {
            $breakdown['gift_type_match'] = $weights['gift_type_match'];
        }

        if ($product->is_featured) {
            $breakdown['featured_boost'] = $weights['featured_boost'];
        }

        $breakdown['total'] = array_sum($breakdown);

        return $breakdown;
    }

    /**
     * @param  Collection<int, array{product: Product, score: float, breakdown: array<string, float|int>, explanation: string}>  $candidates
     * @return Collection<int, array{product: Product, score: float, breakdown: array<string, float|int>, explanation: string}>
     */
    private function sortCandidates(Collection $candidates): Collection
    {
        $tieBreakers = config('gift_recommendations.tie_breakers');

        return $candidates->sort(function (array $a, array $b) use ($tieBreakers): int {
            foreach ($tieBreakers as $breaker) {
                $comparison = match ($breaker) {
                    'score' => $b['score'] <=> $a['score'],
                    'price_amount' => $this->compareNullableAscending(
                        $a['product']->price_amount,
                        $b['product']->price_amount,
                    ),
                    'published_at' => $this->comparePublishedAtDescending(
                        $a['product']->published_at,
                        $b['product']->published_at,
                    ),
                    'id' => $a['product']->id <=> $b['product']->id,
                    default => 0,
                };

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        })->values();
    }

    private function compareNullableAscending(mixed $left, mixed $right): int
    {
        if ($left === null && $right === null) {
            return 0;
        }

        if ($left === null) {
            return 1;
        }

        if ($right === null) {
            return -1;
        }

        return $left <=> $right;
    }

    private function comparePublishedAtDescending(mixed $left, mixed $right): int
    {
        if ($left === null && $right === null) {
            return 0;
        }

        if ($left === null) {
            return 1;
        }

        if ($right === null) {
            return -1;
        }

        return $right <=> $left;
    }

    /**
     * @param  array<string, float|int>  $breakdown
     * @param  list<int>  $interestIds
     */
    private function buildExplanation(array $breakdown, RecommendationSession $session, Product $product, array $interestIds): string
    {
        $parts = [];

        if (($breakdown['occasion_match'] ?? 0) > 0 && $session->occasion instanceof Occasion) {
            $parts[] = $session->occasion->name;
        }

        if (($breakdown['relationship_match'] ?? 0) > 0 && $session->relationship instanceof Relationship) {
            $parts[] = $session->relationship->name;
        }

        if (($breakdown['recipient_type_match'] ?? 0) > 0 && $session->recipientType instanceof RecipientType) {
            $parts[] = $session->recipientType->name;
        }

        if (($breakdown['profession_match'] ?? 0) > 0 && $session->profession instanceof Profession) {
            $parts[] = $session->profession->name;
        }

        if (($breakdown['gift_type_match'] ?? 0) > 0 && $session->giftType instanceof GiftType) {
            $parts[] = $session->giftType->name;
        }

        $interestNames = [];

        if (($breakdown['interest_match'] ?? 0) > 0) {
            $interestNames = $product->interests
                ->whereIn('id', $interestIds)
                ->pluck('name')
                ->all();
        }

        if ($parts === [] && $interestNames === [] && ($breakdown['featured_boost'] ?? 0) <= 0) {
            return 'Recommended published gift.';
        }

        $segments = [];

        if ($parts !== []) {
            $segments[] = 'Matches '.implode(', ', $parts);
        }

        if ($interestNames !== []) {
            $segments[] = 'interests in '.implode(' and ', $interestNames);
        }

        if (($breakdown['featured_boost'] ?? 0) > 0) {
            $segments[] = 'featured gift';
        }

        $explanation = implode(', and ', $segments).'.';

        return str($explanation)->limit(1000)->toString();
    }
}
