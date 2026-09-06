<?php

namespace App\Actions\Discovery;

use App\GiftDetail\GiftDetailPage;
use App\Models\AffiliateLink;
use App\Models\Category;
use App\Models\Product;
use App\Support\DiscoveryUrl;
use App\Support\Money;
use Illuminate\Support\Collection;

class QueryGiftDetailAction
{
    public const GALLERY_LIMIT = 5;

    public function execute(string $slug): ?GiftDetailPage
    {
        $product = Product::query()
            ->published()
            ->where('slug', $slug)
            ->with($this->detailRelations())
            ->first();

        if (! $product instanceof Product) {
            return null;
        }

        $offers = $this->uniqueMerchantOffers($product->affiliateLinks);
        $primary = $offers->firstWhere('is_primary', true) ?? $offers->first();

        return new GiftDetailPage(
            product: $product,
            galleryImages: $product->images->take(self::GALLERY_LIMIT)->values(),
            primaryAffiliateLink: $primary,
            merchantOffers: $offers,
            priceLabel: Money::around($product->price_amount, $product->price_currency),
            badge: $this->badge($product),
            whyItems: $this->whyItems($product->description),
            bestForGroups: $this->bestForGroups($product),
            giftDetailRows: $this->giftDetailRows($product),
        );
    }

    /**
     * @return array<int|string, mixed>
     */
    private function detailRelations(): array
    {
        $activeOrdered = fn (string $table) => fn ($query) => $query
            ->where($table.'.is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');

        return [
            'images' => fn ($query) => $query
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(self::GALLERY_LIMIT),
            'affiliateLinks' => fn ($query) => $query
                ->active()
                ->with('merchant')
                ->orderByDesc('is_primary')
                ->orderBy('id'),
            'categories' => $activeOrdered('categories'),
            'occasions' => $activeOrdered('occasions'),
            'relationships' => $activeOrdered('relationships'),
            'recipientTypes' => $activeOrdered('recipient_types'),
            'interests' => $activeOrdered('interests'),
            'professions' => $activeOrdered('professions'),
            'giftTypes' => $activeOrdered('gift_types'),
        ];
    }

    /**
     * @param  Collection<int, AffiliateLink>  $links
     * @return Collection<int, AffiliateLink>
     */
    private function uniqueMerchantOffers(Collection $links): Collection
    {
        $unique = collect();
        $seenMerchantIds = [];

        foreach ($links as $link) {
            $merchantId = (int) $link->merchant_id;

            if ($link->merchant === null || isset($seenMerchantIds[$merchantId])) {
                continue;
            }

            $seenMerchantIds[$merchantId] = true;
            $unique->push($link);
        }

        $primary = $unique->firstWhere('is_primary', true) ?? $unique->first();

        if ($primary === null) {
            return collect();
        }

        $rest = $unique
            ->reject(fn (AffiliateLink $link): bool => $link->id === $primary->id)
            ->sortBy([
                fn (AffiliateLink $link): string => mb_strtolower((string) $link->merchant?->name),
                fn (AffiliateLink $link): int => (int) $link->id,
            ])
            ->values();

        return collect([$primary])->concat($rest)->values();
    }

    private function badge(Product $product): ?string
    {
        if ($product->is_featured) {
            return 'Featured';
        }

        $isPersonalized = $product->categories->contains(
            fn (Category $category): bool => $category->slug === 'personalized-gifts',
        );

        return $isPersonalized ? 'Personalized' : null;
    }

    /**
     * @return list<string>
     */
    private function whyItems(?string $description): array
    {
        if (! filled($description)) {
            return [];
        }

        $lines = preg_split("/\r\n|\r|\n/", $description) ?: [];

        return collect($lines)
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, items: list<array{label: string, url: string}>}>
     */
    private function bestForGroups(Product $product): array
    {
        $groups = [];

        $recipients = $this->chipItems($product->relationships, fn ($record) => DiscoveryUrl::relationship($record->slug));
        if ($recipients !== []) {
            $groups[] = ['label' => 'Recipients', 'items' => $recipients];
        }

        $occasions = $this->chipItems($product->occasions, fn ($record) => DiscoveryUrl::occasion($record->slug));
        if ($occasions !== []) {
            $groups[] = ['label' => 'Occasions', 'items' => $occasions];
        }

        $interests = $this->chipItems($product->interests, fn ($record) => DiscoveryUrl::interest($record->slug));
        if ($interests !== []) {
            $groups[] = ['label' => 'Interests', 'items' => $interests];
        }

        $giftTypes = $this->chipItems($product->giftTypes, fn ($record) => DiscoveryUrl::giftType($record->slug));
        if ($giftTypes !== []) {
            $groups[] = ['label' => 'Gift types', 'items' => $giftTypes];
        }

        return $groups;
    }

    /**
     * @param  Collection<int, mixed>  $records
     * @param  callable(mixed): string  $url
     * @return list<array{label: string, url: string}>
     */
    private function chipItems(Collection $records, callable $url): array
    {
        return $records
            ->map(fn ($record): array => [
                'label' => (string) $record->name,
                'url' => $url($record),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function giftDetailRows(Product $product): array
    {
        $rows = [];

        if (filled($product->brand)) {
            $rows[] = ['label' => 'Brand', 'value' => (string) $product->brand];
        }

        $this->pushJoinedRow($rows, 'Gift type', $product->giftTypes);
        $this->pushJoinedRow($rows, 'Best for', $product->relationships);
        $this->pushJoinedRow($rows, 'Recipients', $product->recipientTypes);
        $this->pushJoinedRow($rows, 'Occasions', $product->occasions);
        $this->pushJoinedRow($rows, 'Interests', $product->interests);
        $this->pushJoinedRow($rows, 'Profession', $product->professions);

        $primaryCategory = $product->categories->first(
            fn (Category $category): bool => (bool) ($category->pivot->is_primary ?? false),
        );

        if ($primaryCategory instanceof Category) {
            $rows[] = ['label' => 'Category', 'value' => $primaryCategory->name];
        }

        return $rows;
    }

    /**
     * @param  list<array{label: string, value: string}>  $rows
     * @param  Collection<int, mixed>  $records
     */
    private function pushJoinedRow(array &$rows, string $label, Collection $records): void
    {
        $names = $records
            ->map(fn ($record): string => trim((string) $record->name))
            ->filter()
            ->values();

        if ($names->isEmpty()) {
            return;
        }

        $rows[] = ['label' => $label, 'value' => $names->implode(', ')];
    }
}
