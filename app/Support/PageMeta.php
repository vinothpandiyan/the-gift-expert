<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Interest;
use App\Models\Product;
use App\Models\Profession;
use App\Models\SeoLandingPage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Public SEO presentation helpers for discovery pages.
 * URL generation stays in DiscoveryUrl; terminology stays in Terminology.
 */
final class PageMeta
{
    public static function appName(): string
    {
        return (string) config('app.name');
    }

    public static function giftTitle(Product $product): string
    {
        if (filled($product->meta_title)) {
            return (string) $product->meta_title;
        }

        return $product->name.' | '.self::appName();
    }

    public static function giftDescription(Product $product): ?string
    {
        return self::firstFilledText([
            $product->meta_description,
            $product->short_description,
            $product->description,
            $product->name,
        ]);
    }

    public static function giftCanonical(Product $product): string
    {
        if (filled($product->canonical_url) && filter_var($product->canonical_url, FILTER_VALIDATE_URL)) {
            return (string) $product->canonical_url;
        }

        return DiscoveryUrl::gift($product->slug, absolute: true);
    }

    /**
     * @return list<array{label: string, url: ?string}>
     */
    public static function giftBreadcrumbs(Product $product, mixed $context = null): array
    {
        $crumbs = [
            ['label' => 'Home', 'url' => url('/')],
            ['label' => Terminology::giftIdeas(), 'url' => DiscoveryUrl::giftIdeas()],
        ];

        $parent = self::giftBreadcrumbParentFromContext($product, $context)
            ?? self::giftBreadcrumbParent($product);

        if ($parent !== null) {
            $crumbs[] = $parent;
        }

        $crumbs[] = ['label' => $product->name, 'url' => null];

        return $crumbs;
    }

    public static function categoryTitle(Category $category): string
    {
        if (filled($category->meta_title)) {
            return (string) $category->meta_title;
        }

        return $category->name.' '.Terminology::giftIdeas().' | '.self::appName();
    }

    public static function categoryDescription(Category $category): ?string
    {
        return self::firstFilledText([
            $category->meta_description,
            $category->description,
            $category->name,
        ]);
    }

    public static function categoryCanonical(Category $category): string
    {
        return DiscoveryUrl::giftIdeasCategory($category->full_path, absolute: true);
    }

    /**
     * @return list<array{label: string, url: ?string}>
     */
    public static function categoryBreadcrumbs(Category $category): array
    {
        $crumbs = [
            ['label' => 'Home', 'url' => url('/')],
            ['label' => Terminology::giftIdeas(), 'url' => DiscoveryUrl::giftIdeas()],
        ];

        $chain = self::categoryAncestorChain($category);

        foreach ($chain as $index => $item) {
            $isCurrent = $index === array_key_last($chain);
            $crumbs[] = [
                'label' => $item->name,
                'url' => $isCurrent ? null : DiscoveryUrl::giftIdeasCategory($item->full_path),
            ];
        }

        return $crumbs;
    }

    public static function taxonomyTitle(Model $taxonomy, string $taxonomyKey): string
    {
        if (filled($taxonomy->meta_title ?? null)) {
            return (string) $taxonomy->meta_title;
        }

        $name = (string) $taxonomy->name;

        return match ($taxonomyKey) {
            'occasion' => $name.' '.Terminology::gifts().' | '.self::appName(),
            'relationship', 'recipient_type', 'profession' => Terminology::gifts().' for '.$name.' | '.self::appName(),
            'interest' => $name.' '.Terminology::giftIdeas().' | '.self::appName(),
            'gift_type' => $name.' | '.self::appName(),
            default => $name.' | '.self::appName(),
        };
    }

    public static function taxonomyDescription(Model $taxonomy): ?string
    {
        return self::firstFilledText([
            $taxonomy->meta_description ?? null,
            $taxonomy->description ?? null,
            $taxonomy->name ?? null,
        ]);
    }

    public static function taxonomyCanonical(Model $taxonomy, string $taxonomyKey): string
    {
        $slug = (string) $taxonomy->slug;

        return match ($taxonomyKey) {
            'occasion' => DiscoveryUrl::occasion($slug, absolute: true),
            'relationship' => DiscoveryUrl::relationship($slug, absolute: true),
            'recipient_type' => DiscoveryUrl::recipientType($slug, absolute: true),
            'interest' => DiscoveryUrl::interest($slug, absolute: true),
            'profession' => DiscoveryUrl::profession($slug, absolute: true),
            'gift_type' => DiscoveryUrl::giftType($slug, absolute: true),
            default => throw new \InvalidArgumentException("Unknown discovery taxonomy [{$taxonomyKey}]."),
        };
    }

    /**
     * @return list<array{label: string, url: ?string}>
     */
    public static function taxonomyBreadcrumbs(Model $taxonomy, string $taxonomyKey, string $taxonomyLabel): array
    {
        $name = (string) $taxonomy->name;

        if (in_array($taxonomyKey, ['relationship', 'recipient_type'], true)) {
            return [
                ['label' => 'Home', 'url' => url('/')],
                ['label' => Terminology::giftIdeas(), 'url' => DiscoveryUrl::giftIdeas()],
                ['label' => Terminology::gifts().' for '.$name, 'url' => null],
            ];
        }

        return [
            ['label' => 'Home', 'url' => url('/')],
            ['label' => $taxonomyLabel, 'url' => null],
            ['label' => $name, 'url' => null],
        ];
    }

    public static function finderTitle(): string
    {
        return 'Find a Gift | '.self::appName();
    }

    public static function finderDescription(): ?string
    {
        return null;
    }

    public static function finderCanonical(): string
    {
        return DiscoveryUrl::finder(absolute: true);
    }

    public static function finderResultsTitle(): string
    {
        return Terminology::giftRecommendations().' | '.self::appName();
    }

    public static function finderResultsDescription(): ?string
    {
        return Terminology::giftRecommendations();
    }

    public static function giftIdeasTitle(): string
    {
        return Terminology::giftIdeas().' | '.self::appName();
    }

    public static function giftIdeasDescription(): ?string
    {
        return Terminology::giftIdeas();
    }

    public static function giftIdeasCanonical(): string
    {
        return DiscoveryUrl::giftIdeas(absolute: true);
    }

    /**
     * @return list<array{label: string, url: ?string}>
     */
    public static function giftIdeasBreadcrumbs(): array
    {
        return [
            ['label' => 'Home', 'url' => url('/')],
            ['label' => Terminology::giftIdeas(), 'url' => null],
        ];
    }

    public static function seoLandingPageTitle(SeoLandingPage $page): string
    {
        if (filled($page->meta_title)) {
            return (string) $page->meta_title;
        }

        return $page->heading.' | '.self::appName();
    }

    public static function seoLandingPageDescription(SeoLandingPage $page): ?string
    {
        return self::firstFilledText([
            $page->meta_description,
            $page->intro_content,
            $page->heading,
            $page->name,
        ]);
    }

    public static function seoLandingPageCanonical(SeoLandingPage $page): string
    {
        if (filled($page->canonical_url) && filter_var($page->canonical_url, FILTER_VALIDATE_URL)) {
            return (string) $page->canonical_url;
        }

        return DiscoveryUrl::seoLandingPage($page->slug, absolute: true);
    }

    public static function seoLandingPageRobots(SeoLandingPage $page): string
    {
        return $page->is_indexable ? 'index, follow' : 'noindex, follow';
    }

    /**
     * @return list<array{label: string, url: ?string}>
     */
    public static function seoLandingPageBreadcrumbs(SeoLandingPage $page): array
    {
        $crumbs = [
            ['label' => 'Home', 'url' => url('/')],
            ['label' => Terminology::giftIdeas(), 'url' => DiscoveryUrl::giftIdeas()],
        ];

        $parent = self::seoLandingPageBreadcrumbParent($page);

        if ($parent !== null) {
            $crumbs[] = $parent;
        }

        $crumbs[] = ['label' => $page->heading, 'url' => null];

        return $crumbs;
    }

    public static function seoLandingPageProductLinkContext(SeoLandingPage $page): ?string
    {
        $parent = self::seoLandingPageParentDimension($page);

        if ($parent === null) {
            return null;
        }

        [$taxonomyKey, $record] = $parent;
        $value = $taxonomyKey === 'category'
            ? (string) $record->full_path
            : (string) $record->slug;

        if ($value === '') {
            return null;
        }

        return $taxonomyKey.':'.$value;
    }

    /**
     * @return array{label: string, url: string}|null
     */
    private static function seoLandingPageBreadcrumbParent(SeoLandingPage $page): ?array
    {
        $parent = self::seoLandingPageParentDimension($page);

        if ($parent === null) {
            return null;
        }

        return self::discoveryDimensionCrumb($parent[0], $parent[1]);
    }

    /**
     * @return array{0: string, 1: Model}|null
     */
    private static function seoLandingPageParentDimension(SeoLandingPage $page): ?array
    {
        if (self::seoLandingPageDimensionCount($page) < 2) {
            return null;
        }

        $candidates = [
            ['relationship', $page->relationship],
            ['recipient_type', $page->recipientType],
            ['gift_type', $page->giftType],
            ['occasion', $page->occasion],
        ];

        foreach ($candidates as [$taxonomyKey, $record]) {
            if (! $record instanceof Model) {
                continue;
            }

            if (self::discoveryDimensionCrumb($taxonomyKey, $record) !== null) {
                return [$taxonomyKey, $record];
            }
        }

        $interests = $page->relationLoaded('interests')
            ? $page->interests
            : $page->interests()->get();

        if ($interests->count() === 1) {
            $interest = $interests->first();

            if ($interest instanceof Interest && self::discoveryDimensionCrumb('interest', $interest) !== null) {
                return ['interest', $interest];
            }
        }

        $profession = $page->profession;

        if ($profession instanceof Profession && self::discoveryDimensionCrumb('profession', $profession) !== null) {
            return ['profession', $profession];
        }

        $category = $page->category;

        if ($category instanceof Category && self::discoveryDimensionCrumb('category', $category) !== null) {
            return ['category', $category];
        }

        return null;
    }

    /**
     * @return array{label: string, url: string}|null
     */
    private static function giftBreadcrumbParentFromContext(Product $product, mixed $context): ?array
    {
        $parsed = self::parseGiftBrowseContext($context);

        if ($parsed === null) {
            return null;
        }

        [$taxonomyKey, $value] = $parsed;
        $record = self::ownedBrowseContextRecord($product, $taxonomyKey, $value);

        if (! $record instanceof Model) {
            return null;
        }

        return self::discoveryDimensionCrumb($taxonomyKey, $record);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function parseGiftBrowseContext(mixed $context): ?array
    {
        if (! is_string($context) || $context === '' || strlen($context) > 256) {
            return null;
        }

        if (! str_contains($context, ':')) {
            return null;
        }

        [$taxonomyKey, $value] = explode(':', $context, 2);

        if ($taxonomyKey === '' || $value === '') {
            return null;
        }

        if (! in_array($taxonomyKey, [
            'relationship',
            'recipient_type',
            'gift_type',
            'occasion',
            'interest',
            'profession',
            'category',
        ], true)) {
            return null;
        }

        return [$taxonomyKey, $value];
    }

    private static function ownedBrowseContextRecord(Product $product, string $taxonomyKey, string $value): ?Model
    {
        if ($taxonomyKey === 'category') {
            $record = self::relatedRecords($product, 'categories')
                ->first(fn (Category $category): bool => $category->is_active
                    && filled($category->full_path)
                    && (string) $category->full_path === $value);

            return $record instanceof Category ? $record : null;
        }

        $relation = match ($taxonomyKey) {
            'relationship' => 'relationships',
            'recipient_type' => 'recipientTypes',
            'gift_type' => 'giftTypes',
            'occasion' => 'occasions',
            'interest' => 'interests',
            'profession' => 'professions',
            default => null,
        };

        if ($relation === null) {
            return null;
        }

        $record = self::relatedRecords($product, $relation)
            ->first(fn (Model $item): bool => (bool) $item->is_active
                && filled($item->slug)
                && (string) $item->slug === $value);

        return $record instanceof Model ? $record : null;
    }

    /**
     * @return array{label: string, url: string}|null
     */
    private static function giftBreadcrumbParent(Product $product): ?array
    {
        foreach ([
            'relationships' => 'relationship',
            'recipientTypes' => 'recipient_type',
            'giftTypes' => 'gift_type',
            'occasions' => 'occasion',
            'interests' => 'interest',
            'professions' => 'profession',
        ] as $relation => $taxonomyKey) {
            $crumb = self::firstSortedTaxonomyCrumb($product, $relation, $taxonomyKey);

            if ($crumb !== null) {
                return $crumb;
            }
        }

        return self::firstSortedCategoryCrumb($product);
    }

    /**
     * @return array{label: string, url: string}|null
     */
    private static function firstSortedTaxonomyCrumb(Product $product, string $relation, string $taxonomyKey): ?array
    {
        $usable = self::relatedRecords($product, $relation)
            ->filter(fn (Model $record): bool => (bool) $record->is_active && filled($record->slug));

        $record = self::sortByEditorialOrder($usable)->first();

        if (! $record instanceof Model) {
            return null;
        }

        return self::discoveryDimensionCrumb($taxonomyKey, $record);
    }

    /**
     * @return array{label: string, url: string}|null
     */
    private static function firstSortedCategoryCrumb(Product $product): ?array
    {
        $usable = self::relatedRecords($product, 'categories')
            ->filter(fn (Category $category): bool => $category->is_active && filled($category->full_path));

        $primaries = $usable->filter(
            fn (Category $category): bool => (bool) ($category->pivot->is_primary ?? false),
        );

        $pool = $primaries->isNotEmpty() ? $primaries : $usable;
        $category = self::sortByEditorialOrder($pool)->first();

        if (! $category instanceof Category) {
            return null;
        }

        return self::discoveryDimensionCrumb('category', $category);
    }

    /**
     * @return Collection<int, Model>
     */
    private static function relatedRecords(Product $product, string $relation): Collection
    {
        if ($product->relationLoaded($relation)) {
            return $product->{$relation};
        }

        return $product->{$relation}()->get();
    }

    /**
     * @param  Collection<int, Model>  $records
     * @return Collection<int, Model>
     */
    private static function sortByEditorialOrder(Collection $records): Collection
    {
        return $records
            ->sortBy([
                ['sort_order', 'asc'],
                ['slug', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
    }

    /**
     * @return array{label: string, url: string}|null
     */
    private static function discoveryDimensionCrumb(string $taxonomyKey, Model $record): ?array
    {
        if (! (bool) $record->is_active) {
            return null;
        }

        return match ($taxonomyKey) {
            'relationship' => filled($record->slug) ? [
                'label' => Terminology::gifts().' for '.$record->name,
                'url' => DiscoveryUrl::relationship((string) $record->slug),
            ] : null,
            'recipient_type' => filled($record->slug) ? [
                'label' => Terminology::gifts().' for '.$record->name,
                'url' => DiscoveryUrl::recipientType((string) $record->slug),
            ] : null,
            'gift_type' => filled($record->slug) ? [
                'label' => (string) $record->name,
                'url' => DiscoveryUrl::giftType((string) $record->slug),
            ] : null,
            'occasion' => filled($record->slug) ? [
                'label' => $record->name.' '.Terminology::gifts(),
                'url' => DiscoveryUrl::occasion((string) $record->slug),
            ] : null,
            'interest' => filled($record->slug) ? [
                'label' => $record->name.' '.Terminology::giftIdeas(),
                'url' => DiscoveryUrl::interest((string) $record->slug),
            ] : null,
            'profession' => filled($record->slug) ? [
                'label' => Terminology::gifts().' for '.$record->name,
                'url' => DiscoveryUrl::profession((string) $record->slug),
            ] : null,
            'category' => filled($record->full_path) ? [
                'label' => (string) $record->name,
                'url' => DiscoveryUrl::giftIdeasCategory((string) $record->full_path),
            ] : null,
            default => null,
        };
    }

    private static function seoLandingPageDimensionCount(SeoLandingPage $page): int
    {
        $count = 0;

        foreach (['occasion_id', 'relationship_id', 'recipient_type_id', 'profession_id', 'gift_type_id', 'category_id', 'budget_range_id'] as $column) {
            if (filled($page->{$column})) {
                $count++;
            }
        }

        $interestCount = $page->relationLoaded('interests')
            ? $page->interests->count()
            : $page->interests()->count();

        if ($interestCount > 0) {
            $count++;
        }

        return $count;
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array{canonical: string, prev: ?string, next: ?string}
     */
    public static function paginatedCanonicals(LengthAwarePaginator $paginator, string $baseCanonical): array
    {
        $page = $paginator->currentPage();

        return [
            'canonical' => $page <= 1
                ? $baseCanonical
                : $baseCanonical.'?page='.$page,
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];
    }

    /**
     * @return list<Category>
     */
    private static function categoryAncestorChain(Category $category): array
    {
        $chain = [];
        $current = $category;

        while ($current !== null) {
            array_unshift($chain, $current);
            $current = $current->parent;
        }

        return $chain;
    }

    /**
     * @param  list<mixed>  $candidates
     */
    private static function firstFilledText(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $text = trim(preg_replace('/\s+/', ' ', strip_tags($candidate)) ?? '');

            if ($text === '') {
                continue;
            }

            return Str::limit($text, 160, '');
        }

        return null;
    }
}
