<?php

namespace App\Actions\RecipientEligibility;

use App\Actions\Discovery\NormalizeDiscoveryFilterStateAction;
use App\Actions\Discovery\QueryDiscoveryListingProductsAction;
use App\Actions\Discovery\ResolveDiscoveryFilterOptionsAction;
use App\Actions\Recommendation\GenerateRecommendationsAction;
use App\DiscoveryListing\DiscoveryFilterOption;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Enums\AffiliateLinkStatus;
use App\Models\Category;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\RecipientType;
use App\Models\RecommendationSession;
use App\Models\Relationship;
use App\RecipientEligibility\AuditStateMatrix;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only snapshot of the live catalog and current discovery behavior:
 * metrics, a URL matrix (listing IDs per sort, totals, facet counts) and Finder
 * replays. Written once with every eligibility flag off (T0) and re-run later to
 * prove parity or to show the before/after delta.
 */
class BuildRecipientEligibilityBaselineAction
{
    private const SORTS = [
        DiscoveryListingQueryState::SORT_RECOMMENDED,
        DiscoveryListingQueryState::SORT_NEWEST,
        DiscoveryListingQueryState::SORT_PRICE_ASC,
        DiscoveryListingQueryState::SORT_PRICE_DESC,
    ];

    public function __construct(
        private QueryDiscoveryListingProductsAction $queryListing,
        private ResolveDiscoveryFilterOptionsAction $resolveOptions,
        private NormalizeDiscoveryFilterStateAction $normalize,
        private GenerateRecommendationsAction $recommendations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        return [
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'flags' => $this->flagSnapshot(),
            ],
            'catalog_fingerprint' => $this->fingerprint(),
            'metrics' => $this->metrics(),
            'dumbbells' => $this->dumbbells(),
            'url_matrix' => $this->urlMatrix(),
            'finder' => $this->finderReplays(),
        ];
    }

    /**
     * Lists every difference between two baselines (ignores `meta`).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public function diff(array $before, array $after): array
    {
        $before = $this->normalized($before);
        $after = $this->normalized($after);
        $differences = [];

        if (($before['catalog_fingerprint'] ?? null) !== ($after['catalog_fingerprint'] ?? null)) {
            $differences[] = 'catalog_fingerprint differs: the catalog changed between runs, so results are not strictly comparable.';
        }

        foreach (['metrics', 'dumbbells'] as $section) {
            if (($before[$section] ?? null) !== ($after[$section] ?? null)) {
                $differences[] = "{$section} differ.";
            }
        }

        foreach (($before['url_matrix'] ?? []) as $label => $state) {
            $current = $after['url_matrix'][$label] ?? null;

            if ($current === null) {
                $differences[] = "url_matrix[{$label}] missing in new run.";

                continue;
            }

            foreach (['total', 'facets'] as $key) {
                if (($state[$key] ?? null) !== ($current[$key] ?? null)) {
                    $differences[] = "url_matrix[{$label}].{$key} differs.";
                }
            }

            foreach (self::SORTS as $sort) {
                if (($state['sorts'][$sort] ?? null) !== ($current['sorts'][$sort] ?? null)) {
                    $differences[] = "url_matrix[{$label}].sorts.{$sort} ids/order differ.";
                }
            }
        }

        foreach (($before['finder'] ?? []) as $label => $replay) {
            if ($replay !== ($after['finder'][$label] ?? null)) {
                $differences[] = "finder[{$label}] differs.";
            }
        }

        return $differences;
    }

    /**
     * JSON round-trip so a stored file and a fresh run compare with identical scalar types.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalized(array $data): array
    {
        return json_decode((string) json_encode($data), true) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function flagSnapshot(): array
    {
        return [
            'recipient_eligibility' => config('recipient_eligibility.enabled'),
            'surfaces' => config('recipient_eligibility.surfaces'),
            'explicit_contradictions' => config('recipient_eligibility.explicit_contradictions'),
            'finder_audience' => config('recipient_eligibility.finder_audience'),
            'pool_ordering' => config('recipient_eligibility.pool_ordering'),
            'listing_require_active_affiliate' => config('discovery_ranking.listing_require_active_affiliate', false),
            'candidate_pool_max' => config('discovery_ranking.candidate_pool_max'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fingerprint(): array
    {
        $ids = Product::query()->published()->orderBy('id')->pluck('id')->all();

        return [
            'published_count' => count($ids),
            'published_ids_sha1' => sha1(implode(',', $ids)),
            'products_max_updated_at' => (string) Product::query()->published()->max('updated_at'),
            'pivot_rows' => collect([
                'relationship_product',
                'occasion_product',
                'interest_product',
                'recipient_type_product',
                'recipient_gender_product',
                'category_product',
                'gift_type_product',
            ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metrics(): array
    {
        $published = Product::query()->published();
        $publishedIds = (clone $published)->pluck('id');
        $total = $publishedIds->count();

        $withActiveLink = Product::query()->published()
            ->whereHas('affiliateLinks', fn (Builder $q) => $q->where('status', AffiliateLinkStatus::Active))
            ->count();

        $perProduct = Product::query()->published()
            ->withCount(['relationships', 'recipientTypes', 'recipientGenders', 'occasions', 'interests'])
            ->get(['id']);

        $relationshipCounts = DB::table('relationship_product as rp')
            ->join('products as p', 'p.id', '=', 'rp.product_id')
            ->join('relationships as r', 'r.id', '=', 'rp.relationship_id')
            ->where('p.status', 'published')
            ->whereNull('p.deleted_at')
            ->groupBy('r.slug')
            ->orderBy('r.slug')
            ->selectRaw('r.slug as slug, COUNT(*) as c')
            ->pluck('c', 'slug');

        $genderCounts = DB::table('recipient_gender_product as gp')
            ->join('products as p', 'p.id', '=', 'gp.product_id')
            ->join('recipient_genders as g', 'g.id', '=', 'gp.recipient_gender_id')
            ->where('p.status', 'published')
            ->whereNull('p.deleted_at')
            ->groupBy('g.slug')
            ->orderBy('g.slug')
            ->selectRaw('g.slug as slug, COUNT(DISTINCT gp.product_id) as c')
            ->pluck('c', 'slug');

        $recipientTypeCounts = DB::table('recipient_type_product as tp')
            ->join('products as p', 'p.id', '=', 'tp.product_id')
            ->join('recipient_types as t', 't.id', '=', 'tp.recipient_type_id')
            ->where('p.status', 'published')
            ->whereNull('p.deleted_at')
            ->groupBy('t.slug')
            ->orderBy('t.slug')
            ->selectRaw('t.slug as slug, COUNT(DISTINCT tp.product_id) as c')
            ->pluck('c', 'slug');

        return [
            'published' => $total,
            'published_with_active_affiliate_link' => $withActiveLink,
            'classification_status' => Product::query()->published()
                ->select('taxonomy_classification_status', DB::raw('COUNT(*) as c'))
                ->groupBy('taxonomy_classification_status')
                ->orderBy('taxonomy_classification_status')
                ->pluck('c', 'taxonomy_classification_status')
                ->mapWithKeys(fn ($count, $status): array => [(string) ($status instanceof \BackedEnum ? $status->value : $status) => (int) $count])
                ->all(),
            'relationships' => [
                'none' => $perProduct->where('relationships_count', 0)->count(),
                'at_least_one' => $perProduct->where('relationships_count', '>=', 1)->count(),
                'exactly_one' => $perProduct->where('relationships_count', 1)->count(),
                'five_or_more' => $perProduct->where('relationships_count', '>=', 5)->count(),
                'explicit_by_slug' => $relationshipCounts->map(fn ($count): int => (int) $count)->all(),
            ],
            'genders' => [
                'by_slug' => $genderCounts->map(fn ($count): int => (int) $count)->all(),
                'missing' => $perProduct->where('recipient_genders_count', 0)->count(),
            ],
            'recipient_types' => [
                'by_slug' => $recipientTypeCounts->map(fn ($count): int => (int) $count)->all(),
                'products_with_any' => $perProduct->where('recipient_types_count', '>', 0)->count(),
                'inactive_slugs' => RecipientType::query()->where('is_active', false)->orderBy('slug')->pluck('slug')->all(),
            ],
            'no_occasion' => $perProduct->where('occasions_count', 0)->count(),
            'no_interest' => $perProduct->where('interests_count', 0)->count(),
            'landing_pages' => [
                'published' => DB::table('seo_landing_pages')->where('status', 'published')->count(),
                'total' => DB::table('seo_landing_pages')->count(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dumbbells(): array
    {
        return Product::query()->published()
            ->where('name', 'like', '%dumbbell%')
            ->with(['relationships', 'occasions', 'interests', 'recipientGenders', 'recipientTypes', 'categories'])
            ->orderBy('id')
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'classification' => $product->taxonomy_classification_status?->value,
                'relationships' => $product->relationships->pluck('slug')->sort()->values()->all(),
                'occasions' => $product->occasions->pluck('slug')->sort()->values()->all(),
                'interests' => $product->interests->pluck('slug')->sort()->values()->all(),
                'genders' => $product->recipientGenders->pluck('slug')->sort()->values()->all(),
                'recipient_types' => $product->recipientTypes->pluck('slug')->sort()->values()->all(),
                'categories' => $product->categories->pluck('full_path')->sort()->values()->all(),
            ])
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function urlMatrix(): array
    {
        $matrix = [];

        foreach (AuditStateMatrix::states() as $state) {
            $evaluated = $this->evaluateState($state);

            if ($evaluated !== null) {
                $matrix[$state['label']] = $evaluated;
            }
        }

        return $matrix;
    }

    /**
     * @param  array{label: string, surface: string, slug: string|null, query: array<string, string>, control: bool}  $state
     * @return array<string, mixed>|null
     */
    public function evaluateState(array $state): ?array
    {
        $context = $this->listingContext($state['surface'], $state['slug']);

        if (! $context instanceof DiscoveryListingContext) {
            return null;
        }

        $perPage = max(1, (int) config('discovery_ranking.per_page', 12));
        $baseState = $this->normalize->execute($context, DiscoveryListingQueryState::fromQuery($state['query']));
        $total = $this->queryListing->count($context, $baseState);
        $throughPage = max(1, (int) ceil($total / $perPage));
        $sorts = [];

        foreach (self::SORTS as $sort) {
            $sorted = DiscoveryListingQueryState::fromQuery($state['query'] + ['sort' => $sort]);
            $sorted = $this->normalize->execute($context, $sorted);
            $paginator = $this->queryListing->execute($context, $sorted, 1, $throughPage);
            $sorts[$sort] = [
                'total' => $paginator->total(),
                'ids' => $paginator->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            ];
        }

        $facets = [];

        foreach ($this->resolveOptions->execute($context, $baseState) as $dimension => $options) {
            $facets[$dimension] = $options
                ->mapWithKeys(fn (DiscoveryFilterOption $option): array => [$option->slug => $option->count])
                ->sortKeys()
                ->all();
        }

        ksort($facets);

        return [
            'surface' => $state['surface'],
            'control' => $state['control'],
            'total' => $total,
            'pages' => $throughPage,
            'sorts' => $sorts,
            'facets' => $facets,
        ];
    }

    public function listingContext(string $surface, ?string $slug): ?DiscoveryListingContext
    {
        return match ($surface) {
            'relationship' => ($record = Relationship::query()->where('slug', $slug)->where('is_active', true)->first())
                ? DiscoveryListingContext::forRelationship($record) : null,
            'occasion' => ($record = Occasion::query()->where('slug', $slug)->where('is_active', true)->first())
                ? DiscoveryListingContext::forOccasion($record) : null,
            'interest' => ($record = Interest::query()->where('slug', $slug)->where('is_active', true)->first())
                ? DiscoveryListingContext::forInterest($record) : null,
            'recipient_type' => ($record = RecipientType::query()->where('slug', $slug)->where('is_active', true)->first())
                ? DiscoveryListingContext::forRecipientType($record) : null,
            'category' => ($record = Category::query()->where('full_path', $slug)->where('is_active', true)->first())
                ? DiscoveryListingContext::forCategory($record) : null,
            'gift_ideas' => DiscoveryListingContext::forGiftIdeas(),
            default => null,
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function finderReplays(): array
    {
        $replays = [];

        foreach ($this->finderInputs() as $label => $input) {
            $replays[$label] = $this->replay($input);
        }

        return $replays;
    }

    /**
     * Stored golden sessions plus the extra audit scenarios, as Finder inputs.
     *
     * @return array<string, array<string, mixed>>
     */
    public function finderInputs(): array
    {
        $inputs = [];

        $sessions = RecommendationSession::query()
            ->whereIn('id', (array) config('recipient_eligibility.audit.golden_session_ids', []))
            ->with('interests:id')
            ->orderBy('id')
            ->get();

        foreach ($sessions as $session) {
            $inputs['golden:'.$session->id] = [
                'occasion_id' => $session->occasion_id,
                'budget_range_id' => $session->budget_range_id,
                'relationship_id' => $session->relationship_id,
                'recipient_type_id' => $session->recipient_type_id,
                'recipient_gender_id' => $session->recipient_gender_id,
                'profession_id' => $session->profession_id,
                'gift_type_id' => $session->gift_type_id,
                'interest_ids' => $session->interests->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ];
        }

        foreach (AuditStateMatrix::extraFinderInputs() as $label => $answers) {
            $inputs[$label] = [
                'occasion_id' => Occasion::query()->where('slug', $answers['occasion'])->value('id'),
                'relationship_id' => Relationship::query()->where('slug', $answers['relationship'])->value('id'),
                'interest_ids' => Interest::query()->whereIn('slug', $answers['interests'])->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ];
        }

        return $inputs;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function replay(array $input): array
    {
        $ranked = $this->recommendations->preview($input);
        $groups = [];

        foreach ($ranked as $candidate) {
            $groups[$candidate['group']] = ($groups[$candidate['group']] ?? 0) + 1;
        }

        ksort($groups);

        return [
            'input' => $input,
            'top' => collect($ranked)->map(fn (array $candidate): array => [
                'id' => $candidate['product']->id,
                'group' => $candidate['group'],
                'score' => round($candidate['score'], 2),
            ])->all(),
            'group_counts' => $groups,
            'stage1_ids' => $this->recommendations->interestStageProductIds($input),
        ];
    }
}
