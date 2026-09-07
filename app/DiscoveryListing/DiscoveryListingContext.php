<?php

namespace App\DiscoveryListing;

use App\Enums\TaxonomyDimension;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Support\PageMeta;
use App\Support\SeoLandingPageEditorial;
use InvalidArgumentException;

final class DiscoveryListingContext
{
    /**
     * Preferred public filter order. Page-specific contexts omit dimensions
     * they already fix; remaining dimensions keep this relative order.
     *
     * @var list<string>
     */
    private const DIMENSION_ORDER = [
        'relationship',
        'occasion',
        'category',
        'interest',
        'gift_type',
        'profession',
        'recipient',
        'budget',
    ];

    /** @var list<string> */
    public readonly array $availableDimensions;

    /**
     * @param  array{
     *     occasion_id?: int|null,
     *     relationship_id?: int|null,
     *     recipient_type_id?: int|null,
     *     profession_id?: int|null,
     *     gift_type_id?: int|null,
     *     category_id?: int|null,
     *     budget_range_id?: int|null,
     *     interest_ids?: list<int>,
     * }  $fixedFilters
     * @param  list<string>  $availableDimensions
     * @param  list<int>  $hiddenInterestIds
     */
    public function __construct(
        public readonly string $surface,
        public readonly array $fixedFilters,
        array $availableDimensions,
        public readonly string $browseContext,
        public readonly array $hiddenInterestIds = [],
    ) {
        $this->availableDimensions = self::orderDimensions($availableDimensions);
    }

    /**
     * @return array{
     *     surface: string,
     *     fixedFilters: array<string, mixed>,
     *     availableDimensions: list<string>,
     *     browseContext: string,
     *     hiddenInterestIds: list<int>,
     * }
     */
    public function toArray(): array
    {
        return [
            'surface' => $this->surface,
            'fixedFilters' => $this->fixedFilters,
            'availableDimensions' => $this->availableDimensions,
            'browseContext' => $this->browseContext,
            'hiddenInterestIds' => $this->hiddenInterestIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            surface: (string) ($data['surface'] ?? ''),
            fixedFilters: is_array($data['fixedFilters'] ?? null) ? $data['fixedFilters'] : [],
            availableDimensions: array_values($data['availableDimensions'] ?? []),
            browseContext: (string) ($data['browseContext'] ?? ''),
            hiddenInterestIds: array_values($data['hiddenInterestIds'] ?? []),
        );
    }

    public static function forRelationship(Relationship $relationship): self
    {
        return new self(
            surface: 'relationship',
            fixedFilters: ['relationship_id' => $relationship->id],
            availableDimensions: ['occasion', 'budget', 'interest', 'gift_type', 'category'],
            browseContext: 'relationship:'.$relationship->slug,
        );
    }

    public static function forOccasion(Occasion $occasion): self
    {
        return new self(
            surface: 'occasion',
            fixedFilters: ['occasion_id' => $occasion->id],
            availableDimensions: ['relationship', 'recipient', 'budget', 'interest', 'gift_type', 'category'],
            browseContext: 'occasion:'.$occasion->slug,
        );
    }

    public static function forRecipientType(RecipientType $recipientType): self
    {
        return new self(
            surface: 'recipient_type',
            fixedFilters: ['recipient_type_id' => $recipientType->id],
            availableDimensions: ['relationship', 'occasion', 'budget', 'interest', 'gift_type'],
            browseContext: 'recipient_type:'.$recipientType->slug,
        );
    }

    public static function forInterest(Interest $interest): self
    {
        return new self(
            surface: 'interest',
            fixedFilters: ['interest_ids' => [$interest->id]],
            availableDimensions: ['relationship', 'occasion', 'budget', 'gift_type', 'category'],
            browseContext: 'interest:'.$interest->slug,
            hiddenInterestIds: [$interest->id],
        );
    }

    public static function forProfession(Profession $profession): self
    {
        return new self(
            surface: 'profession',
            fixedFilters: ['profession_id' => $profession->id],
            availableDimensions: ['relationship', 'occasion', 'budget', 'interest', 'gift_type'],
            browseContext: 'profession:'.$profession->slug,
        );
    }

    public static function forGiftType(GiftType $giftType): self
    {
        return new self(
            surface: 'gift_type',
            fixedFilters: ['gift_type_id' => $giftType->id],
            availableDimensions: ['relationship', 'occasion', 'budget', 'interest', 'category'],
            browseContext: 'gift_type:'.$giftType->slug,
        );
    }

    public static function forCategory(Category $category): self
    {
        return new self(
            surface: 'category',
            fixedFilters: ['category_id' => $category->id],
            availableDimensions: ['relationship', 'occasion', 'budget', 'interest', 'gift_type'],
            browseContext: 'category:'.$category->full_path,
        );
    }

    public static function forGiftIdeas(): self
    {
        return new self(
            surface: 'gift_ideas',
            fixedFilters: [],
            availableDimensions: [
                'relationship',
                'occasion',
                'recipient',
                'profession',
                'budget',
                'interest',
                'gift_type',
                'category',
            ],
            browseContext: 'gift_ideas',
        );
    }

    public static function forSeoLandingPage(SeoLandingPage $page): self
    {
        $filters = SeoLandingPageEditorial::productFilters($page);
        $available = [];

        if ($filters['relationship_id'] === null) {
            $available[] = 'relationship';
        }
        if ($filters['occasion_id'] === null) {
            $available[] = 'occasion';
        }
        if ($filters['recipient_type_id'] === null) {
            $available[] = 'recipient';
        }
        if ($filters['profession_id'] === null) {
            $available[] = 'profession';
        }
        if ($filters['gift_type_id'] === null) {
            $available[] = 'gift_type';
        }
        if ($filters['category_id'] === null) {
            $available[] = 'category';
        }
        if ($filters['budget_range_id'] === null) {
            $available[] = 'budget';
        }

        $available[] = 'interest';

        return new self(
            surface: 'seo_landing',
            fixedFilters: $filters,
            availableDimensions: $available,
            browseContext: (string) (PageMeta::seoLandingPageProductLinkContext($page) ?? ''),
            hiddenInterestIds: $filters['interest_ids'],
        );
    }

    public static function forTaxonomy(string $taxonomy, Relationship|Occasion|RecipientType|Interest|Profession|GiftType $record): self
    {
        return match ($taxonomy) {
            'relationship' => $record instanceof Relationship
                ? self::forRelationship($record)
                : throw new InvalidArgumentException('Expected Relationship model.'),
            'occasion' => $record instanceof Occasion
                ? self::forOccasion($record)
                : throw new InvalidArgumentException('Expected Occasion model.'),
            'recipient_type' => $record instanceof RecipientType
                ? self::forRecipientType($record)
                : throw new InvalidArgumentException('Expected RecipientType model.'),
            'interest' => $record instanceof Interest
                ? self::forInterest($record)
                : throw new InvalidArgumentException('Expected Interest model.'),
            'profession' => $record instanceof Profession
                ? self::forProfession($record)
                : throw new InvalidArgumentException('Expected Profession model.'),
            'gift_type' => $record instanceof GiftType
                ? self::forGiftType($record)
                : throw new InvalidArgumentException('Expected GiftType model.'),
            default => throw new InvalidArgumentException("Unknown discovery taxonomy [{$taxonomy}]."),
        };
    }

    public function allows(string $dimension): bool
    {
        return in_array($dimension, $this->availableDimensions, true);
    }

    public static function dimensionLabel(string $dimension): string
    {
        return match ($dimension) {
            'occasion' => 'Occasion',
            'relationship' => 'Relationship',
            'recipient' => 'Recipient',
            'budget' => 'Budget',
            'interest' => 'Interest',
            'profession' => 'Profession',
            'gift_type' => 'Gift Type',
            'category' => 'Category',
            default => $dimension,
        };
    }

    /**
     * @param  list<string>  $dimensions
     * @return list<string>
     */
    private static function orderDimensions(array $dimensions): array
    {
        $rank = array_flip(self::DIMENSION_ORDER);

        $ordered = array_values($dimensions);
        usort($ordered, function (string $left, string $right) use ($rank): int {
            return ($rank[$left] ?? 99) <=> ($rank[$right] ?? 99);
        });

        return $ordered;
    }

    /**
     * Semantic taxonomy contexts from this listing's fixed filters.
     * BudgetRange is not a semantic applicability dimension.
     *
     * @return list<array{dimension: TaxonomyDimension, id: int}>
     */
    public function semanticTaxonomyContexts(): array
    {
        return TaxonomyDimension::contextsFromFilters($this->fixedFilters);
    }
}
