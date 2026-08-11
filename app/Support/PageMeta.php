<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
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
    public static function giftBreadcrumbs(Product $product): array
    {
        $crumbs = [
            ['label' => 'Home', 'url' => url('/')],
            ['label' => Terminology::giftIdeas(), 'url' => null],
        ];

        $primaryCategory = $product->categories->first(fn (Category $category): bool => (bool) $category->pivot->is_primary)
            ?? $product->categories->first();

        if ($primaryCategory instanceof Category) {
            foreach (self::categoryAncestorChain($primaryCategory) as $ancestor) {
                $crumbs[] = [
                    'label' => $ancestor->name,
                    'url' => DiscoveryUrl::giftIdeasCategory($ancestor->full_path),
                ];
            }
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
            ['label' => Terminology::giftIdeas(), 'url' => null],
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
                ['label' => Terminology::giftIdeas(), 'url' => null],
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
