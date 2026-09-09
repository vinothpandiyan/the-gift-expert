<?php

namespace Tests\Feature\ProductImage;

use App\Actions\ProductImage\BackfillAmazonProductImagesAction;
use App\Actions\ProductImage\ProcessProductImageAction;
use App\Actions\ProductImage\ReplaceAutomaticallyAcquiredProductImageAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesRasterImages;
use Tests\TestCase;

class BackfillAmazonProductImagesTest extends TestCase
{
    use MakesRasterImages;
    use RefreshDatabase;

    public function test_it_replaces_low_resolution_automatic_amazon_images_without_touching_taxonomy(): void
    {
        Storage::fake('public');
        $highRes = (string) file_get_contents($this->rasterImagePath(1200, 1200, 'jpeg'));
        Http::preventStrayRequests();
        Http::fake([
            'https://m.media-amazon.com/images/I/411lYXWd-cL._SS1200_.jpg' => Http::response(
                $highRes,
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);

        [$product, $image] = $this->amazonProductWithImage(
            sourceUrl: 'https://m.media-amazon.com/images/W/BW_MEDIAX_AVIF_MEASUREMENT_1306696-T3/images/I/411lYXWd-cL._SS135_.jpg',
            storedWidth: 135,
        );

        $fingerprint = 'fingerprint-before';
        $product->forceFill([
            'taxonomy_classification_status' => TaxonomyClassificationStatus::AiAccepted,
            'taxonomy_content_fingerprint' => $fingerprint,
            'taxonomy_relationship_hint_fingerprint' => 'hint-before',
            'name' => 'Giftplease Personalized Best Friend Acrylic Night Light',
            'description' => 'A thoughtful paragraph.',
        ])->save();

        $result = app(BackfillAmazonProductImagesAction::class)->execute(productId: $product->id);

        $this->assertSame(1, $result->replaced);
        $this->assertSame(0, $result->failed);

        $freshImage = $image->fresh();
        $freshProduct = $product->fresh();

        $this->assertSame(
            'https://m.media-amazon.com/images/I/411lYXWd-cL._SS1200_.jpg',
            $freshImage->source_url,
        );
        $this->assertGreaterThanOrEqual(1000, app(ReplaceAutomaticallyAcquiredProductImageAction::class)->storedLongEdge($freshImage));
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $freshProduct->taxonomy_classification_status);
        $this->assertSame($fingerprint, $freshProduct->taxonomy_content_fingerprint);
        $this->assertSame('hint-before', $freshProduct->taxonomy_relationship_hint_fingerprint);
        $this->assertSame('Giftplease Personalized Best Friend Acrylic Night Light', $freshProduct->name);
        $this->assertSame('A thoughtful paragraph.', $freshProduct->description);
        $this->assertSame(ProductStatus::Draft, $freshProduct->status);
        $this->assertNull($freshProduct->published_at);
    }

    public function test_dry_run_does_not_write_and_second_run_is_idempotent(): void
    {
        Storage::fake('public');
        $highRes = (string) file_get_contents($this->rasterImagePath(1200, 1200, 'jpeg'));
        Http::fake([
            'https://m.media-amazon.com/images/*' => Http::response($highRes, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        [$product, $image] = $this->amazonProductWithImage(
            sourceUrl: 'https://m.media-amazon.com/images/I/411lYXWd-cL._SS135_.jpg',
            storedWidth: 135,
        );
        $originalPath = $image->path;
        $originalSource = $image->source_url;

        $dry = app(BackfillAmazonProductImagesAction::class)->execute(productId: $product->id, dryRun: true);
        $this->assertSame(1, $dry->replaced);
        $this->assertTrue($dry->dryRun);
        $this->assertSame($originalPath, $image->fresh()->path);
        $this->assertSame($originalSource, $image->fresh()->source_url);

        $first = app(BackfillAmazonProductImagesAction::class)->execute(productId: $product->id);
        $this->assertSame(1, $first->replaced);

        $canonicalPath = $image->fresh()->path;

        $second = app(BackfillAmazonProductImagesAction::class)->execute(productId: $product->id);
        $this->assertSame(0, $second->replaced);
        $this->assertSame(1, $second->skippedAlreadyHighResolution);
        $this->assertSame($canonicalPath, $image->fresh()->path);
    }

    public function test_it_does_not_replace_operator_managed_or_non_amazon_images(): void
    {
        Storage::fake('public');
        Http::fake();

        [$amazon] = $this->amazonProductWithImage(
            sourceUrl: 'https://m.media-amazon.com/images/I/411lYXWd-cL._SS135_.jpg',
            storedWidth: 135,
            acquired: false,
        );
        $amazon->images()->first()?->update(['source_url' => null, 'acquired_at' => null]);

        $flipkart = Merchant::query()->create([
            'name' => 'Flipkart',
            'slug' => 'flipkart',
            'affiliate_network' => 'flipkart',
            'is_active' => true,
        ]);
        $other = Product::factory()->draft()->create();
        AffiliateLink::query()->create([
            'product_id' => $other->id,
            'merchant_id' => $flipkart->id,
            'url' => 'https://www.flipkart.com/x',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);
        ProductImage::query()->create([
            'product_id' => $other->id,
            'disk' => 'public',
            'path' => 'products/'.$other->id.'/images/flipkart.webp',
            'is_primary' => true,
            'sort_order' => 0,
            'source_url' => 'https://rukminim2.flixcart.com/image/416/416/xif0q/foo.jpeg',
            'acquired_at' => now(),
        ]);

        $operatorResult = app(BackfillAmazonProductImagesAction::class)->execute(productId: $amazon->id);
        $this->assertSame(0, $operatorResult->examined);
        $this->assertSame(0, $operatorResult->replaced);

        $otherResult = app(BackfillAmazonProductImagesAction::class)->execute(productId: $other->id);
        $this->assertSame(1, $otherResult->skippedNotAmazon);
        $this->assertSame(0, $otherResult->replaced);
        Http::assertNothingSent();
    }

    public function test_command_requires_intake_run_or_product(): void
    {
        $this->artisan('catalog:backfill-amazon-images')
            ->expectsOutputToContain('Pass --intake-run=<id> or --product=<id>.')
            ->assertFailed();
    }

    /**
     * @return array{0: Product, 1: ProductImage}
     */
    private function amazonProductWithImage(string $sourceUrl, int $storedWidth, bool $acquired = true): array
    {
        $merchant = Merchant::query()->create([
            'name' => 'Amazon India',
            'slug' => 'amazon-in',
            'affiliate_network' => 'amazon_associates',
            'is_active' => true,
        ]);
        $product = Product::factory()->draft()->create([
            'taxonomy_classification_status' => TaxonomyClassificationStatus::AiAccepted,
        ]);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
            'external_product_id' => 'B0ABCDEFGH',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);

        $path = 'products/'.$product->id.'/images/thumb.webp';
        $processed = app(ProcessProductImageAction::class)
            ->execute($this->rasterImagePath($storedWidth, $storedWidth, 'jpeg'));
        Storage::disk('public')->put($path, $processed->contents);

        $image = ProductImage::query()->create([
            'product_id' => $product->id,
            'disk' => 'public',
            'path' => $path,
            'alt_text' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
            'source_url' => $sourceUrl,
            'content_hash' => hash('sha256', $processed->contents),
            'acquired_at' => $acquired ? now() : null,
        ]);

        return [$product->fresh(['images', 'affiliateLinks.merchant']), $image];
    }
}
