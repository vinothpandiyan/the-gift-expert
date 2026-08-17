<?php

namespace App\Actions\Import;

use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Import\ImportedCatalogItem;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class UpsertImportedProductAction
{
    public function execute(Merchant $merchant, ImportedCatalogItem $item): AffiliateLink
    {
        if (blank($item->external_product_id) || blank($item->name) || blank($item->affiliate_url)) {
            throw new InvalidArgumentException('Imported catalog items require a name, affiliate URL, and external product ID.');
        }

        return DB::transaction(function () use ($merchant, $item): AffiliateLink {
            $link = AffiliateLink::withTrashed()
                ->where('merchant_id', $merchant->id)
                ->where('external_product_id', $item->external_product_id)
                ->first();

            if ($link !== null) {
                return $this->updateExisting($link, $item);
            }

            return $this->createNew($merchant, $item);
        });
    }

    private function createNew(Merchant $merchant, ImportedCatalogItem $item): AffiliateLink
    {
        $product = Product::query()->create([
            'name' => $item->name,
            'slug' => $this->uniqueSlug((string) $item->name),
            'short_description' => $item->short_description,
            'description' => $item->description,
            'brand' => $item->brand,
            'status' => ProductStatus::Draft,
            'price_amount' => $item->price_amount,
            'price_currency' => $item->price_currency ?? 'INR',
            'published_at' => null,
        ]);

        return $product->affiliateLinks()->create([
            'merchant_id' => $merchant->id,
            'url' => $item->affiliate_url,
            'external_product_id' => $item->external_product_id,
            'is_primary' => $product->affiliateLinks()->doesntExist(),
            'status' => AffiliateLinkStatus::Active,
            'last_verified_at' => now(),
        ]);
    }

    private function updateExisting(AffiliateLink $link, ImportedCatalogItem $item): AffiliateLink
    {
        if ($link->trashed()) {
            $link->restore();
        }

        $product = Product::withTrashed()->findOrFail($link->product_id);

        if ($product->trashed()) {
            $product->restore();
        }

        $hasOtherPrimary = $product->affiliateLinks()
            ->whereKeyNot($link->id)
            ->where('is_primary', true)
            ->exists();

        $link->fill([
            'url' => $item->affiliate_url,
            'status' => AffiliateLinkStatus::Active,
            'last_verified_at' => now(),
            'is_primary' => $hasOtherPrimary ? $link->is_primary : true,
        ]);
        $link->save();

        $product->price_amount = $item->price_amount;
        $product->price_currency = $item->price_currency ?? $product->price_currency;

        if ($product->status === ProductStatus::Draft) {
            $product->name = $item->name;
            $product->short_description = $item->short_description;
            $product->description = $item->description;
            $product->brand = $item->brand;
        }

        $product->save();

        return $link->fresh();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'gift';
        }

        $slug = $base;
        $suffix = 2;

        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
