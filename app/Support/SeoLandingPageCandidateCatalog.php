<?php

namespace App\Support;

use App\Actions\Product\QueryPublishedProductsByFiltersAction;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use RuntimeException;

final class SeoLandingPageCandidateCatalog
{
    public const APPROVE = 'APPROVE';

    public const HOLD = 'HOLD';

    public const REJECT = 'REJECT';

    public const APPROVE_MIN_PUBLISHED_PRODUCTS = 2;

    /**
     * Editorial candidate matrix. Composite intents only; not auto-published.
     *
     * @return list<array{
     *     name: string,
     *     slug: string,
     *     heading: string,
     *     relationship: ?string,
     *     occasion: ?string,
     *     recipient_type: ?string,
     *     profession: ?string,
     *     gift_type: ?string,
     *     category: ?string,
     *     budget_range: ?string,
     *     interests: list<string>,
     * }>
     */
    public static function definitions(): array
    {
        return [
            self::candidate('Birthday Gifts for Husband', 'birthday-gifts-for-husband', relationship: 'husband', occasion: 'birthday'),
            self::candidate('Anniversary Gifts for Husband', 'anniversary-gifts-for-husband', relationship: 'husband', occasion: 'anniversary'),
            self::candidate('Birthday Gifts for Wife', 'birthday-gifts-for-wife', relationship: 'wife', occasion: 'birthday'),
            self::candidate('Anniversary Gifts for Wife', 'anniversary-gifts-for-wife', relationship: 'wife', occasion: 'anniversary'),
            self::candidate('Birthday Gifts for Boyfriend', 'birthday-gifts-for-boyfriend', relationship: 'boyfriend', occasion: 'birthday'),
            self::candidate('Anniversary Gifts for Boyfriend', 'anniversary-gifts-for-boyfriend', relationship: 'boyfriend', occasion: 'anniversary'),
            self::candidate('Birthday Gifts for Girlfriend', 'birthday-gifts-for-girlfriend', relationship: 'girlfriend', occasion: 'birthday'),
            self::candidate('Birthday Gifts for Dad', 'birthday-gifts-for-dad', relationship: 'father', occasion: 'birthday'),
            self::candidate('Birthday Gifts for Mom', 'birthday-gifts-for-mom', relationship: 'mother', occasion: 'birthday'),
            self::candidate('Birthday Gifts for Brother', 'birthday-gifts-for-brother', relationship: 'brother', occasion: 'birthday'),
            self::candidate('Birthday Gifts for Sister', 'birthday-gifts-for-sister', relationship: 'sister', occasion: 'birthday'),
            self::candidate('Gifts for Husband Who Loves Coffee', 'gifts-for-husband-who-loves-coffee', relationship: 'husband', interests: ['coffee']),
            self::candidate('Gifts for Husband Who Loves Technology', 'gifts-for-husband-who-loves-technology', relationship: 'husband', interests: ['technology']),
            self::candidate('Gifts for Husband Who Loves Photography', 'gifts-for-husband-who-loves-photography', relationship: 'husband', interests: ['photography']),
            self::candidate('Gifts for Wife Who Loves Fitness', 'gifts-for-wife-who-loves-fitness', relationship: 'wife', interests: ['fitness']),
            self::candidate('Gifts for Wife Who Loves Food', 'gifts-for-wife-who-loves-food', relationship: 'wife', interests: ['food']),
            self::candidate('Birthday Gifts for Coffee Lovers', 'birthday-gifts-for-coffee-lovers', occasion: 'birthday', interests: ['coffee']),
            self::candidate('Birthday Gifts for Tech Lovers', 'birthday-gifts-for-tech-lovers', occasion: 'birthday', interests: ['technology']),
            self::candidate('Farewell Gifts for Colleagues', 'farewell-gifts-for-colleagues', relationship: 'colleagues', occasion: 'farewell'),
            self::candidate('Housewarming Gifts for Parents', 'housewarming-gifts-for-parents', relationship: 'parents', occasion: 'housewarming'),
            self::candidate('Diwali Gifts for Parents', 'diwali-gifts-for-parents', relationship: 'parents', occasion: 'diwali'),
            self::candidate('Wedding Gifts for Newlyweds', 'wedding-gifts-for-newlyweds', relationship: 'newlyweds', occasion: 'wedding'),
            self::candidate('Birthday Gifts for Kids', 'birthday-gifts-for-kids', recipient_type: 'kids', occasion: 'birthday'),
            self::candidate('Birthday Gifts for Teachers', 'birthday-gifts-for-teachers', profession: 'teacher', occasion: 'birthday'),
            self::candidate('Retirement Gifts for Doctors', 'retirement-gifts-for-doctors', profession: 'doctor', occasion: 'retirement'),
            self::candidate(
                'Birthday Gifts for Husband Who Loves Coffee',
                'birthday-gifts-for-husband-who-loves-coffee',
                relationship: 'husband',
                occasion: 'birthday',
                interests: ['coffee'],
            ),
        ];
    }

    /**
     * @return list<array{
     *     name: string,
     *     slug: string,
     *     heading: string,
     *     filters: array{
     *         occasion_id: int|null,
     *         relationship_id: int|null,
     *         recipient_type_id: int|null,
     *         profession_id: int|null,
     *         gift_type_id: int|null,
     *         category_id: int|null,
     *         budget_range_id: int|null,
     *         interest_ids: list<int>,
     *     },
     *     published_product_count: int,
     *     dimension_count: int,
     *     recommendation: string,
     *     reason: string,
     *     cannibalization_risk: string,
     * }>
     */
    public static function evaluate(?QueryPublishedProductsByFiltersAction $queryProducts = null): array
    {
        $queryProducts ??= app(QueryPublishedProductsByFiltersAction::class);
        $existingSignatures = self::existingFilterSignatures();
        $rows = [];

        foreach (self::definitions() as $definition) {
            $filters = self::filtersFromDefinition($definition);
            $dimensionCount = self::dimensionCount($filters);
            $productCount = $queryProducts->execute($filters)->count();
            $signatureKey = self::signatureKey($filters);
            $signatureTaken = isset($existingSignatures[$signatureKey])
                && $existingSignatures[$signatureKey] !== $definition['slug'];

            [$recommendation, $reason, $cannibalizationRisk] = self::recommend(
                slug: $definition['slug'],
                filters: $filters,
                productCount: $productCount,
                dimensionCount: $dimensionCount,
                signatureTaken: $signatureTaken,
            );

            $rows[] = [
                'name' => $definition['name'],
                'slug' => $definition['slug'],
                'heading' => $definition['heading'],
                'filters' => $filters,
                'published_product_count' => $productCount,
                'dimension_count' => $dimensionCount,
                'recommendation' => $recommendation,
                'reason' => $reason,
                'cannibalization_risk' => $cannibalizationRisk,
            ];
        }

        return $rows;
    }

    /**
     * @param  array{
     *     occasion_id: int|null,
     *     relationship_id: int|null,
     *     recipient_type_id: int|null,
     *     profession_id: int|null,
     *     gift_type_id: int|null,
     *     category_id: int|null,
     *     budget_range_id: int|null,
     *     interest_ids: list<int>,
     * }  $filters
     * @return array{0: string, 1: string, 2: string}
     */
    public static function recommend(
        string $slug,
        array $filters,
        int $productCount,
        int $dimensionCount,
        bool $signatureTaken,
    ): array {
        if (SeoLandingPageEditorial::duplicatesTaxonomyIntent($filters)) {
            return [self::REJECT, 'Duplicates a single-dimension taxonomy URL.', 'High — taxonomy URL already covers this intent.'];
        }

        if ($filters['budget_range_id'] !== null) {
            return [self::REJECT, 'Budget landing pages are deferred until budget has a public URL model.', 'Medium — overlaps price-filtered browsing.'];
        }

        if ($filters['gift_type_id'] !== null) {
            return [self::REJECT, 'Gift-type composites are not part of the initial editorial batch.', 'High — gift-type taxonomy URL already exists.'];
        }

        if ($filters['category_id'] !== null) {
            return [self::REJECT, 'Category filters stay on merchandising category URLs unless a mapping is justified.', 'High — category URL already exists.'];
        }

        if ($signatureTaken) {
            return [self::REJECT, 'Another SEO landing page already uses this filter signature.', 'High — duplicate filter signature.'];
        }

        if ($slug === 'birthday-gifts-for-husband') {
            return [self::REJECT, 'Already seeded as the published Birthday Gifts for Husband page; do not create a second record.', 'High — this slug and signature are live.'];
        }

        if ($dimensionCount >= 3) {
            return [self::HOLD, 'A third dimension is only justified when the catalog can support a distinct listing; keep the two-dimension pages instead.', 'High — overlaps the parent relationship + occasion or relationship + interest page.'];
        }

        if ($productCount < self::APPROVE_MIN_PUBLISHED_PRODUCTS) {
            $reason = $productCount === 0
                ? 'No published gifts match this composite filter yet.'
                : 'Only one published gift matches; that is too thin for a useful listing.';

            return [self::HOLD, $reason, $productCount === 0 ? 'Low — empty listing, not a competing URL yet.' : 'Medium — listing would largely repeat a single gift.'];
        }

        if ($filters['profession_id'] !== null && $productCount < 3) {
            return [self::HOLD, 'Profession composites need a deeper catalog before they are worth a dedicated page.', 'Medium — overlaps relationship or recipient browsing.'];
        }

        return [self::APPROVE, 'Composite intent is distinct from taxonomy URLs and the catalog has enough matching published gifts.', 'Low — taxonomy URLs remain the single-dimension canonicals.'];
    }

    /**
     * @param  array{
     *     name: string,
     *     slug: string,
     *     heading: string,
     *     relationship: ?string,
     *     occasion: ?string,
     *     recipient_type: ?string,
     *     profession: ?string,
     *     gift_type: ?string,
     *     category: ?string,
     *     budget_range: ?string,
     *     interests: list<string>,
     * }  $definition
     * @return array{
     *     occasion_id: int|null,
     *     relationship_id: int|null,
     *     recipient_type_id: int|null,
     *     profession_id: int|null,
     *     gift_type_id: int|null,
     *     category_id: int|null,
     *     budget_range_id: int|null,
     *     interest_ids: list<int>,
     * }
     */
    public static function filtersFromDefinition(array $definition): array
    {
        $interestIds = [];

        foreach ($definition['interests'] as $interestSlug) {
            $interestIds[] = self::idOrFail(Interest::class, $interestSlug);
        }

        return [
            'occasion_id' => self::nullableId(Occasion::class, $definition['occasion']),
            'relationship_id' => self::nullableId(Relationship::class, $definition['relationship']),
            'recipient_type_id' => self::nullableId(RecipientType::class, $definition['recipient_type']),
            'profession_id' => self::nullableId(Profession::class, $definition['profession']),
            'gift_type_id' => self::nullableId(GiftType::class, $definition['gift_type']),
            'category_id' => null,
            'budget_range_id' => null,
            'interest_ids' => SeoLandingPageEditorial::normalizedInterestIds($interestIds),
        ];
    }

    /**
     * @param  array{
     *     occasion_id: int|null,
     *     relationship_id: int|null,
     *     recipient_type_id: int|null,
     *     profession_id: int|null,
     *     gift_type_id: int|null,
     *     category_id: int|null,
     *     budget_range_id: int|null,
     *     interest_ids: list<int>,
     * }  $filters
     */
    public static function dimensionCount(array $filters): int
    {
        $count = 0;

        foreach (['occasion_id', 'relationship_id', 'recipient_type_id', 'profession_id', 'gift_type_id', 'category_id', 'budget_range_id'] as $column) {
            if ($filters[$column] !== null) {
                $count++;
            }
        }

        if ($filters['interest_ids'] !== []) {
            $count++;
        }

        return $count;
    }

    /**
     * @param  array{
     *     occasion_id: int|null,
     *     relationship_id: int|null,
     *     recipient_type_id: int|null,
     *     profession_id: int|null,
     *     gift_type_id: int|null,
     *     category_id: int|null,
     *     budget_range_id: int|null,
     *     interest_ids: list<int>,
     * }  $filters
     */
    public static function signatureKey(array $filters): string
    {
        return implode(':', [
            $filters['occasion_id'] ?? 'n',
            $filters['relationship_id'] ?? 'n',
            $filters['recipient_type_id'] ?? 'n',
            $filters['profession_id'] ?? 'n',
            $filters['gift_type_id'] ?? 'n',
            $filters['category_id'] ?? 'n',
            $filters['budget_range_id'] ?? 'n',
            implode(',', $filters['interest_ids']),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function existingFilterSignatures(): array
    {
        $signatures = [];

        foreach (SeoLandingPage::query()->with('interests')->get() as $page) {
            $signatures[self::signatureKey(SeoLandingPageEditorial::productFilters($page))] = $page->slug;
        }

        return $signatures;
    }

    /**
     * @param  list<string>  $interests
     * @return array{
     *     name: string,
     *     slug: string,
     *     heading: string,
     *     relationship: ?string,
     *     occasion: ?string,
     *     recipient_type: ?string,
     *     profession: ?string,
     *     gift_type: ?string,
     *     category: ?string,
     *     budget_range: ?string,
     *     interests: list<string>,
     * }
     */
    private static function candidate(
        string $name,
        string $slug,
        ?string $relationship = null,
        ?string $occasion = null,
        ?string $recipient_type = null,
        ?string $profession = null,
        ?string $gift_type = null,
        array $interests = [],
    ): array {
        return [
            'name' => $name,
            'slug' => $slug,
            'heading' => $name,
            'relationship' => $relationship,
            'occasion' => $occasion,
            'recipient_type' => $recipient_type,
            'profession' => $profession,
            'gift_type' => $gift_type,
            'category' => null,
            'budget_range' => null,
            'interests' => $interests,
        ];
    }

    /**
     * @param  class-string  $model
     */
    private static function nullableId(string $model, ?string $slug): ?int
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return self::idOrFail($model, $slug);
    }

    /**
     * @param  class-string  $model
     */
    private static function idOrFail(string $model, string $slug): int
    {
        $id = $model::query()->where('slug', $slug)->value('id');

        if ($id === null) {
            throw new RuntimeException("Missing taxonomy slug [{$slug}] for {$model}.");
        }

        return (int) $id;
    }
}
