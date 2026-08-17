<?php

namespace Tests\Feature\Import;

use App\Enums\AffiliateLinkStatus;
use App\Enums\ImportRunItemStatus;
use App\Enums\ImportRunStatus;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\ImportRun;
use App\Models\ImportRunItem;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportFoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_non_null_merchant_and_external_product_id_is_rejected(): void
    {
        $merchant = $this->merchant();
        $first = $this->product('first-gift');
        $second = $this->product('second-gift');

        $this->affiliateLink($first, $merchant, 'ASIN-1');

        $this->expectException(QueryException::class);

        $this->affiliateLink($second, $merchant, 'ASIN-1');
    }

    public function test_multiple_null_external_product_ids_are_allowed_for_the_same_merchant(): void
    {
        $merchant = $this->merchant();

        $first = $this->affiliateLink($this->product('manual-one'), $merchant, null);
        $second = $this->affiliateLink($this->product('manual-two'), $merchant, null);

        $this->assertNull($first->external_product_id);
        $this->assertNull($second->external_product_id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($merchant->id, $first->merchant_id);
        $this->assertSame($merchant->id, $second->merchant_id);
        $this->assertNotSame($first->uuid, $second->uuid);
    }

    public function test_same_external_product_id_can_exist_on_different_merchants(): void
    {
        $amazon = $this->merchant('Amazon India', 'amazon-india', 'amazon_associates');
        $flipkart = $this->merchant('Flipkart', 'flipkart', 'flipkart_affiliate');
        $product = $this->product('shared-gift');

        $amazonLink = $this->affiliateLink($product, $amazon, 'SHARED-1');
        $flipkartLink = $this->affiliateLink($this->product('other-gift'), $flipkart, 'SHARED-1');

        $this->assertSame('SHARED-1', $amazonLink->external_product_id);
        $this->assertSame('SHARED-1', $flipkartLink->external_product_id);
        $this->assertNotSame($amazonLink->merchant_id, $flipkartLink->merchant_id);
    }

    public function test_affiliate_link_uuid_is_preserved_when_identity_fields_are_set(): void
    {
        $link = $this->affiliateLink($this->product('uuid-gift'), $this->merchant(), 'ASIN-UUID');
        $uuid = $link->uuid;

        $link->update(['url' => 'https://example.com/updated']);

        $this->assertSame($uuid, $link->fresh()->uuid);
    }

    public function test_import_run_belongs_to_merchant_and_has_items(): void
    {
        $merchant = $this->merchant();
        $product = $this->product('imported-gift');
        $link = $this->affiliateLink($product, $merchant, 'ASIN-IMPORT');

        $run = ImportRun::query()->create([
            'merchant_id' => $merchant->id,
            'provider_key' => $merchant->affiliate_network,
            'status' => ImportRunStatus::Pending,
        ]);

        $item = ImportRunItem::query()->create([
            'import_run_id' => $run->id,
            'external_product_id' => 'ASIN-IMPORT',
            'product_id' => $product->id,
            'affiliate_link_id' => $link->id,
            'status' => ImportRunItemStatus::Succeeded,
            'source_payload' => ['name' => 'Imported Gift'],
        ]);

        $this->assertTrue($run->merchant->is($merchant));
        $this->assertTrue($run->items->contains($item));
        $this->assertTrue($item->importRun->is($run));
        $this->assertTrue($item->product->is($product));
        $this->assertTrue($item->affiliateLink->is($link));
        $this->assertTrue($merchant->importRuns->contains($run));
        $this->assertTrue($product->importRunItems->contains($item));
        $this->assertTrue($link->importRunItems->contains($item));
        $this->assertSame(['name' => 'Imported Gift'], $item->source_payload);
    }

    public function test_duplicate_external_product_id_within_an_import_run_is_rejected(): void
    {
        $run = ImportRun::query()->create([
            'merchant_id' => $this->merchant()->id,
            'provider_key' => 'fake',
            'status' => ImportRunStatus::Pending,
        ]);

        ImportRunItem::query()->create([
            'import_run_id' => $run->id,
            'external_product_id' => 'ASIN-DUP',
            'status' => ImportRunItemStatus::Pending,
        ]);

        $this->expectException(QueryException::class);

        ImportRunItem::query()->create([
            'import_run_id' => $run->id,
            'external_product_id' => 'ASIN-DUP',
            'status' => ImportRunItemStatus::Pending,
        ]);
    }

    public function test_deleting_an_import_run_cascades_items(): void
    {
        $run = ImportRun::query()->create([
            'merchant_id' => $this->merchant()->id,
            'provider_key' => 'fake',
            'status' => ImportRunStatus::Pending,
        ]);

        $item = ImportRunItem::query()->create([
            'import_run_id' => $run->id,
            'external_product_id' => 'ASIN-CASCADE',
            'status' => ImportRunItemStatus::Pending,
        ]);

        $run->delete();

        $this->assertDatabaseMissing('import_runs', ['id' => $run->id]);
        $this->assertDatabaseMissing('import_run_items', ['id' => $item->id]);
    }

    public function test_merchant_with_import_runs_cannot_be_force_deleted(): void
    {
        $merchant = $this->merchant();

        ImportRun::query()->create([
            'merchant_id' => $merchant->id,
            'provider_key' => $merchant->affiliate_network,
            'status' => ImportRunStatus::Pending,
        ]);

        $this->expectException(QueryException::class);

        $merchant->forceDelete();
    }

    public function test_force_deleting_a_product_nulls_import_run_item_product_id(): void
    {
        $merchant = $this->merchant();
        $product = $this->product('nullable-product');
        $run = ImportRun::query()->create([
            'merchant_id' => $merchant->id,
            'provider_key' => 'fake',
            'status' => ImportRunStatus::Pending,
        ]);
        $item = ImportRunItem::query()->create([
            'import_run_id' => $run->id,
            'external_product_id' => 'ASIN-NULL-PRODUCT',
            'product_id' => $product->id,
            'status' => ImportRunItemStatus::Succeeded,
        ]);

        $product->forceDelete();

        $this->assertNull($item->fresh()->product_id);
        $this->assertDatabaseHas('import_runs', ['id' => $run->id]);
    }

    public function test_force_deleting_an_affiliate_link_nulls_import_run_item_affiliate_link_id(): void
    {
        $merchant = $this->merchant();
        $product = $this->product('nullable-link');
        $link = $this->affiliateLink($product, $merchant, 'ASIN-NULL-LINK');
        $run = ImportRun::query()->create([
            'merchant_id' => $merchant->id,
            'provider_key' => 'fake',
            'status' => ImportRunStatus::Pending,
        ]);
        $item = ImportRunItem::query()->create([
            'import_run_id' => $run->id,
            'external_product_id' => 'ASIN-NULL-LINK',
            'affiliate_link_id' => $link->id,
            'status' => ImportRunItemStatus::Succeeded,
        ]);

        $link->forceDelete();

        $this->assertNull($item->fresh()->affiliate_link_id);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_product_image_provenance_columns_are_nullable_and_not_backfilled(): void
    {
        $product = $this->product('image-gift');

        $image = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/'.$product->id.'/images/manual.webp',
            'is_primary' => true,
        ]);

        $image->refresh();

        $this->assertNull($image->source_url);
        $this->assertNull($image->content_hash);
        $this->assertNull($image->acquired_at);
    }

    public function test_product_image_provenance_columns_can_be_stored(): void
    {
        $product = $this->product('provenance-gift');
        $acquiredAt = now()->startOfSecond();

        $image = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/'.$product->id.'/images/imported.webp',
            'is_primary' => true,
            'source_url' => 'https://example.com/image.jpg',
            'content_hash' => str_repeat('a', 64),
            'acquired_at' => $acquiredAt,
        ]);

        $image->refresh();

        $this->assertSame('https://example.com/image.jpg', $image->source_url);
        $this->assertSame(str_repeat('a', 64), $image->content_hash);
        $this->assertTrue($acquiredAt->equalTo($image->acquired_at));
    }

    private function merchant(
        string $name = 'Example Merchant',
        string $slug = 'example-merchant',
        string $network = 'example',
    ): Merchant {
        return Merchant::query()->create([
            'name' => $name,
            'slug' => $slug,
            'affiliate_network' => $network,
        ]);
    }

    private function product(string $slug): Product
    {
        return Product::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'status' => ProductStatus::Draft,
        ]);
    }

    private function affiliateLink(Product $product, Merchant $merchant, ?string $externalProductId): AffiliateLink
    {
        return AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://example.com/'.$product->slug,
            'external_product_id' => $externalProductId,
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);
    }
}
