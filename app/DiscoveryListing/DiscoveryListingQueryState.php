<?php

namespace App\DiscoveryListing;

use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use Illuminate\Http\Request;

final class DiscoveryListingQueryState
{
    public const SORT_RECOMMENDED = 'recommended';

    public const SORT_PRICE_ASC = 'price_asc';

    public const SORT_PRICE_DESC = 'price_desc';

    public const SORT_NEWEST = 'newest';

    public const USER_QUERY_KEYS = [
        'occasion',
        'relationship',
        'recipient',
        'interest',
        'profession',
        'gift_type',
        'category',
        'budget',
        'sort',
    ];

    /**
     * @param  list<string>  $occasionSlugs
     * @param  list<string>  $relationshipSlugs
     * @param  list<string>  $recipientSlugs
     * @param  list<string>  $interestSlugs
     * @param  list<string>  $professionSlugs
     * @param  list<string>  $giftTypeSlugs
     * @param  list<string>  $categoryPaths
     */
    public function __construct(
        public readonly array $occasionSlugs = [],
        public readonly array $relationshipSlugs = [],
        public readonly array $recipientSlugs = [],
        public readonly array $interestSlugs = [],
        public readonly array $professionSlugs = [],
        public readonly array $giftTypeSlugs = [],
        public readonly array $categoryPaths = [],
        public readonly ?string $budgetSlug = null,
        public readonly string $sort = self::SORT_RECOMMENDED,
        public readonly int $page = 1,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return self::fromQuery($request->query());
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $sort = (string) ($query['sort'] ?? '');
        $page = (int) ($query['page'] ?? 1);

        return new self(
            occasionSlugs: self::decodeList($query['occasion'] ?? ''),
            relationshipSlugs: self::decodeList($query['relationship'] ?? ''),
            recipientSlugs: self::decodeList($query['recipient'] ?? ''),
            interestSlugs: self::decodeList($query['interest'] ?? ''),
            professionSlugs: self::decodeList($query['profession'] ?? ''),
            giftTypeSlugs: self::decodeList($query['gift_type'] ?? ''),
            categoryPaths: self::decodeList($query['category'] ?? ''),
            budgetSlug: self::nullableString($query['budget'] ?? null),
            sort: in_array($sort, self::sorts(), true) ? $sort : self::SORT_RECOMMENDED,
            page: $page > 0 ? $page : 1,
        );
    }

    public static function requestHasUserState(?Request $request = null): bool
    {
        $query = ($request ?? request())->query();

        foreach (self::USER_QUERY_KEYS as $key) {
            if (filled($query[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function sorts(): array
    {
        return [
            self::SORT_RECOMMENDED,
            self::SORT_PRICE_ASC,
            self::SORT_PRICE_DESC,
            self::SORT_NEWEST,
        ];
    }

    /**
     * @return list<string>
     */
    public static function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = explode(',', (string) $value);
        }

        return collect($parts)
            ->map(fn ($part) => trim((string) $part))
            ->filter(fn ($part) => $part !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $values
     */
    public static function encodeList(array $values): string
    {
        return collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->unique()
            ->sort()
            ->implode(',');
    }

    public function hasUserFiltersOrSort(): bool
    {
        return $this->occasionSlugs !== []
            || $this->relationshipSlugs !== []
            || $this->recipientSlugs !== []
            || $this->interestSlugs !== []
            || $this->professionSlugs !== []
            || $this->giftTypeSlugs !== []
            || $this->categoryPaths !== []
            || $this->budgetSlug !== null
            || $this->sort !== self::SORT_RECOMMENDED;
    }

    public function isRecommended(): bool
    {
        return $this->sort === self::SORT_RECOMMENDED;
    }

    /**
     * Restrict user-applied dimensions to those the listing context allows.
     */
    public function scopedTo(DiscoveryListingContext $context): self
    {
        return new self(
            occasionSlugs: $context->allows('occasion') ? $this->occasionSlugs : [],
            relationshipSlugs: $context->allows('relationship') ? $this->relationshipSlugs : [],
            recipientSlugs: $context->allows('recipient') ? $this->recipientSlugs : [],
            interestSlugs: $context->allows('interest') ? $this->interestSlugs : [],
            professionSlugs: $context->allows('profession') ? $this->professionSlugs : [],
            giftTypeSlugs: $context->allows('gift_type') ? $this->giftTypeSlugs : [],
            categoryPaths: $context->allows('category') ? $this->categoryPaths : [],
            budgetSlug: $context->allows('budget') ? $this->budgetSlug : null,
            sort: $this->sort,
            page: $this->page,
        );
    }

    /**
     * Drop user-selected values for one listing dimension so facet counts
     * for that dimension stay disjunctive.
     */
    public function withoutUserDimension(string $dimension): self
    {
        return new self(
            occasionSlugs: $dimension === 'occasion' ? [] : $this->occasionSlugs,
            relationshipSlugs: $dimension === 'relationship' ? [] : $this->relationshipSlugs,
            recipientSlugs: $dimension === 'recipient' ? [] : $this->recipientSlugs,
            interestSlugs: $dimension === 'interest' ? [] : $this->interestSlugs,
            professionSlugs: $dimension === 'profession' ? [] : $this->professionSlugs,
            giftTypeSlugs: $dimension === 'gift_type' ? [] : $this->giftTypeSlugs,
            categoryPaths: $dimension === 'category' ? [] : $this->categoryPaths,
            budgetSlug: $dimension === 'budget' ? null : $this->budgetSlug,
            sort: $this->sort,
            page: $this->page,
        );
    }

    public function withoutUserTaxonomy(): self
    {
        return new self(
            budgetSlug: $this->budgetSlug,
            sort: $this->sort,
            page: $this->page,
        );
    }

    /**
     * @return list<string>
     */
    public function slugsFor(string $dimension): array
    {
        return match ($dimension) {
            'occasion' => $this->occasionSlugs,
            'relationship' => $this->relationshipSlugs,
            'recipient' => $this->recipientSlugs,
            'interest' => $this->interestSlugs,
            'profession' => $this->professionSlugs,
            'gift_type' => $this->giftTypeSlugs,
            'category' => $this->categoryPaths,
            'budget' => $this->budgetSlug !== null ? [$this->budgetSlug] : [],
            default => [],
        };
    }

    /**
     * @param  list<string>  $slugs
     */
    public function withSlugsFor(string $dimension, array $slugs): self
    {
        $slugs = self::decodeList($slugs);

        return match ($dimension) {
            'occasion' => new self(
                occasionSlugs: $slugs,
                relationshipSlugs: $this->relationshipSlugs,
                recipientSlugs: $this->recipientSlugs,
                interestSlugs: $this->interestSlugs,
                professionSlugs: $this->professionSlugs,
                giftTypeSlugs: $this->giftTypeSlugs,
                categoryPaths: $this->categoryPaths,
                budgetSlug: $this->budgetSlug,
                sort: $this->sort,
                page: $this->page,
            ),
            'relationship' => new self(
                occasionSlugs: $this->occasionSlugs,
                relationshipSlugs: $slugs,
                recipientSlugs: $this->recipientSlugs,
                interestSlugs: $this->interestSlugs,
                professionSlugs: $this->professionSlugs,
                giftTypeSlugs: $this->giftTypeSlugs,
                categoryPaths: $this->categoryPaths,
                budgetSlug: $this->budgetSlug,
                sort: $this->sort,
                page: $this->page,
            ),
            'recipient' => new self(
                occasionSlugs: $this->occasionSlugs,
                relationshipSlugs: $this->relationshipSlugs,
                recipientSlugs: $slugs,
                interestSlugs: $this->interestSlugs,
                professionSlugs: $this->professionSlugs,
                giftTypeSlugs: $this->giftTypeSlugs,
                categoryPaths: $this->categoryPaths,
                budgetSlug: $this->budgetSlug,
                sort: $this->sort,
                page: $this->page,
            ),
            'interest' => new self(
                occasionSlugs: $this->occasionSlugs,
                relationshipSlugs: $this->relationshipSlugs,
                recipientSlugs: $this->recipientSlugs,
                interestSlugs: $slugs,
                professionSlugs: $this->professionSlugs,
                giftTypeSlugs: $this->giftTypeSlugs,
                categoryPaths: $this->categoryPaths,
                budgetSlug: $this->budgetSlug,
                sort: $this->sort,
                page: $this->page,
            ),
            'profession' => new self(
                occasionSlugs: $this->occasionSlugs,
                relationshipSlugs: $this->relationshipSlugs,
                recipientSlugs: $this->recipientSlugs,
                interestSlugs: $this->interestSlugs,
                professionSlugs: $slugs,
                giftTypeSlugs: $this->giftTypeSlugs,
                categoryPaths: $this->categoryPaths,
                budgetSlug: $this->budgetSlug,
                sort: $this->sort,
                page: $this->page,
            ),
            'gift_type' => new self(
                occasionSlugs: $this->occasionSlugs,
                relationshipSlugs: $this->relationshipSlugs,
                recipientSlugs: $this->recipientSlugs,
                interestSlugs: $this->interestSlugs,
                professionSlugs: $this->professionSlugs,
                giftTypeSlugs: $slugs,
                categoryPaths: $this->categoryPaths,
                budgetSlug: $this->budgetSlug,
                sort: $this->sort,
                page: $this->page,
            ),
            'category' => new self(
                occasionSlugs: $this->occasionSlugs,
                relationshipSlugs: $this->relationshipSlugs,
                recipientSlugs: $this->recipientSlugs,
                interestSlugs: $this->interestSlugs,
                professionSlugs: $this->professionSlugs,
                giftTypeSlugs: $this->giftTypeSlugs,
                categoryPaths: $slugs,
                budgetSlug: $this->budgetSlug,
                sort: $this->sort,
                page: $this->page,
            ),
            'budget' => new self(
                occasionSlugs: $this->occasionSlugs,
                relationshipSlugs: $this->relationshipSlugs,
                recipientSlugs: $this->recipientSlugs,
                interestSlugs: $this->interestSlugs,
                professionSlugs: $this->professionSlugs,
                giftTypeSlugs: $this->giftTypeSlugs,
                categoryPaths: $this->categoryPaths,
                budgetSlug: $slugs[0] ?? null,
                sort: $this->sort,
                page: $this->page,
            ),
            default => $this,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toProductFilters(DiscoveryListingContext $context): array
    {
        $state = $this->scopedTo($context);

        $filters = $context->fixedFilters;

        $occasionIds = $this->activeIds(Occasion::class, $state->occasionSlugs);
        $relationshipIds = $this->activeIds(Relationship::class, $state->relationshipSlugs);
        $recipientIds = $this->activeIds(RecipientType::class, $state->recipientSlugs);
        $professionIds = $this->activeIds(Profession::class, $state->professionSlugs);
        $giftTypeIds = $this->activeIds(GiftType::class, $state->giftTypeSlugs);
        $interestIds = $this->activeIds(Interest::class, $state->interestSlugs);
        $hiddenInterests = $context->hiddenInterestIds;
        $userInterests = array_values(array_diff($interestIds, $hiddenInterests));

        if ($occasionIds !== []) {
            $filters['occasion_ids'] = $occasionIds;
        }
        if ($relationshipIds !== []) {
            $filters['relationship_ids'] = $relationshipIds;
        }
        if ($recipientIds !== []) {
            $filters['recipient_type_ids'] = $recipientIds;
        }
        if ($professionIds !== []) {
            $filters['profession_ids'] = $professionIds;
        }
        if ($giftTypeIds !== []) {
            $filters['gift_type_ids'] = $giftTypeIds;
        }
        if ($userInterests !== []) {
            $filters['any_interest_ids'] = $userInterests;
        }

        $categoryIds = $this->activeCategoryIds($state->categoryPaths);

        if ($categoryIds !== []) {
            $filters['category_ids'] = $categoryIds;
        }

        if ($state->budgetSlug !== null) {
            $budgetId = BudgetRange::query()
                ->where('slug', $state->budgetSlug)
                ->where('is_active', true)
                ->value('id');

            if ($budgetId !== null) {
                $filters['budget_range_id'] = (int) $budgetId;
            }
        }

        return $filters;
    }

    /**
     * @return array<string, string>
     */
    public function toPaginatorQuery(): array
    {
        $query = [];

        $pairs = [
            'occasion' => self::encodeList($this->occasionSlugs),
            'relationship' => self::encodeList($this->relationshipSlugs),
            'recipient' => self::encodeList($this->recipientSlugs),
            'interest' => self::encodeList($this->interestSlugs),
            'profession' => self::encodeList($this->professionSlugs),
            'gift_type' => self::encodeList($this->giftTypeSlugs),
            'category' => self::encodeList($this->categoryPaths),
            'budget' => $this->budgetSlug ?? '',
            'sort' => $this->sort === self::SORT_RECOMMENDED ? '' : $this->sort,
        ];

        foreach ($pairs as $key => $value) {
            if ($value !== '') {
                $query[$key] = $value;
            }
        }

        return $query;
    }

    /**
     * @param  class-string  $model
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function activeIds(string $model, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return $model::query()
            ->whereIn('slug', $slugs)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<string>  $paths
     * @return list<int>
     */
    private function activeCategoryIds(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        return Category::query()
            ->whereIn('full_path', $paths)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
