<?php

namespace App\Support;

use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\RecipientType;
use App\Models\Relationship;
use Illuminate\Support\Collection;

final class GiftIdeasHub
{
    /**
     * @return array{
     *     recipients: list<array{name: string, href: string, slug: string}>,
     *     occasions: list<array{name: string, href: string, slug: string}>,
     *     interests: list<array{name: string, href: string, slug: string}>,
     *     giftTypes: list<array{name: string, href: string, slug: string}>,
     *     categories: list<array{name: string, href: string, slug: string}>,
     *     budgetRanges: Collection<int, BudgetRange>,
     * }
     */
    public static function resolve(): array
    {
        $config = config('gift_ideas_hub');

        return [
            'recipients' => self::recipients($config['recipients'] ?? []),
            'occasions' => self::orderedLinks(
                Occasion::class,
                $config['occasions'] ?? [],
                fn (Occasion $record): string => DiscoveryUrl::occasion($record->slug),
            ),
            'interests' => self::orderedLinks(
                Interest::class,
                $config['interests'] ?? [],
                fn (Interest $record): string => DiscoveryUrl::interest($record->slug),
            ),
            'giftTypes' => self::orderedLinks(
                GiftType::class,
                $config['gift_types'] ?? [],
                fn (GiftType $record): string => DiscoveryUrl::giftType($record->slug),
            ),
            'categories' => self::categories($config['categories'] ?? []),
            'budgetRanges' => BudgetRange::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        ];
    }

    /**
     * @param  list<array{taxonomy: string, slug: string}>  $definitions
     * @return list<array{name: string, href: string, slug: string}>
     */
    private static function recipients(array $definitions): array
    {
        $relationshipSlugs = [];
        $recipientTypeSlugs = [];

        foreach ($definitions as $definition) {
            if (($definition['taxonomy'] ?? '') === 'relationship') {
                $relationshipSlugs[] = $definition['slug'];
            }

            if (($definition['taxonomy'] ?? '') === 'recipient_type') {
                $recipientTypeSlugs[] = $definition['slug'];
            }
        }

        $relationships = Relationship::query()
            ->where('is_active', true)
            ->whereIn('slug', $relationshipSlugs)
            ->get()
            ->keyBy('slug');
        $recipientTypes = RecipientType::query()
            ->where('is_active', true)
            ->whereIn('slug', $recipientTypeSlugs)
            ->get()
            ->keyBy('slug');

        $links = [];

        foreach ($definitions as $definition) {
            $slug = $definition['slug'] ?? '';

            if (($definition['taxonomy'] ?? '') === 'relationship') {
                $record = $relationships->get($slug);

                if ($record instanceof Relationship) {
                    $links[] = [
                        'name' => $record->name,
                        'href' => DiscoveryUrl::relationship($record->slug),
                        'slug' => $record->slug,
                    ];
                }
            }

            if (($definition['taxonomy'] ?? '') === 'recipient_type') {
                $record = $recipientTypes->get($slug);

                if ($record instanceof RecipientType) {
                    $links[] = [
                        'name' => $record->name,
                        'href' => DiscoveryUrl::recipientType($record->slug),
                        'slug' => $record->slug,
                    ];
                }
            }
        }

        return $links;
    }

    /**
     * @param  class-string  $modelClass
     * @param  list<string>  $slugs
     * @param  callable(object): string  $href
     * @return list<array{name: string, href: string, slug: string}>
     */
    private static function orderedLinks(string $modelClass, array $slugs, callable $href): array
    {
        $records = $modelClass::query()
            ->where('is_active', true)
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy('slug');

        $links = [];

        foreach ($slugs as $slug) {
            $record = $records->get($slug);

            if ($record === null) {
                continue;
            }

            $links[] = [
                'name' => $record->name,
                'href' => $href($record),
                'slug' => $record->slug,
            ];
        }

        return $links;
    }

    /**
     * @param  list<string>  $slugs
     * @return list<array{name: string, href: string, slug: string}>
     */
    private static function categories(array $slugs): array
    {
        $records = Category::query()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy('slug');

        $links = [];

        foreach ($slugs as $slug) {
            $record = $records->get($slug);

            if (! $record instanceof Category) {
                continue;
            }

            $links[] = [
                'name' => $record->name,
                'href' => DiscoveryUrl::giftIdeasCategory($record->full_path),
                'slug' => $record->slug,
            ];
        }

        return $links;
    }
}
