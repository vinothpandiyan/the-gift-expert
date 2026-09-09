<?php

namespace Tests\Feature\ProductImage;

use App\Actions\ProductImage\NormalizeAmazonProductImageContentAction;
use App\Actions\ProductImage\ReplaceAutomaticallyAcquiredProductImageAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NormalizeAmazonProductImageContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_safe_amazon_whitespace_without_changing_product_state_or_source_truth(): void
    {
        Storage::fake('public');
        [$product, $image] = $this->amazonProductWithImage($this->uniformBackgroundImage(), acquired: true);
        $originalPath = $image->path;
        $sourceUrl = $image->source_url;
        $contentHash = $image->content_hash;

        $dryRun = app(NormalizeAmazonProductImageContentAction::class)
            ->execute(productId: $product->id, dryRun: true);

        $this->assertSame(1, $dryRun->normalized);
        $this->assertSame($originalPath, $image->fresh()->path);
        $this->assertGreaterThan($dryRun->averageOccupancyBefore, $dryRun->averageOccupancyAfter);

        $result = app(NormalizeAmazonProductImageContentAction::class)
            ->execute(productId: $product->id);

        $this->assertSame(1, $result->normalized);
        $freshImage = $image->fresh();
        $freshProduct = $product->fresh();
        $this->assertNotSame($originalPath, $freshImage->path);
        $this->assertFalse(Storage::disk('public')->exists($originalPath));
        $this->assertSame(600, app(ReplaceAutomaticallyAcquiredProductImageAction::class)->storedLongEdge($freshImage));
        $this->assertSame($sourceUrl, $freshImage->source_url);
        $this->assertSame($contentHash, $freshImage->content_hash);
        $this->assertSame(TaxonomyClassificationStatus::AiAccepted, $freshProduct->taxonomy_classification_status);
        $this->assertSame('classification-before', $freshProduct->taxonomy_content_fingerprint);
        $this->assertSame('Editorial name', $freshProduct->name);
        $this->assertSame('Editorial description', $freshProduct->description);
        $this->assertSame(ProductStatus::Draft, $freshProduct->status);
        $this->assertNull($freshProduct->published_at);

        $second = app(NormalizeAmazonProductImageContentAction::class)
            ->execute(productId: $product->id);

        $this->assertSame(0, $second->normalized);
        $this->assertSame(1, $second->skippedAlreadyNormalized);
        $this->assertSame($freshImage->path, $image->fresh()->path);
    }

    public function test_it_skips_operator_managed_and_unsafe_images(): void
    {
        Storage::fake('public');
        [$operatorProduct, $operatorImage] = $this->amazonProductWithImage(
            $this->uniformBackgroundImage(),
            acquired: false,
        );
        [$unsafeProduct, $unsafeImage] = $this->amazonProductWithImage(
            $this->nonUniformImage(),
            acquired: true,
        );

        $operator = app(NormalizeAmazonProductImageContentAction::class)
            ->execute(productId: $operatorProduct->id);
        $unsafe = app(NormalizeAmazonProductImageContentAction::class)
            ->execute(productId: $unsafeProduct->id);

        $this->assertSame(1, $operator->skippedOperatorManaged);
        $this->assertSame(0, $operator->normalized);
        $this->assertSame('products/'.$operatorProduct->id.'/images/source.webp', $operatorImage->fresh()->path);
        $this->assertSame(1, $unsafe->skippedUnsafe);
        $this->assertSame(0, $unsafe->normalized);
        $this->assertSame('products/'.$unsafeProduct->id.'/images/source.webp', $unsafeImage->fresh()->path);
    }

    public function test_command_requires_an_explicit_scope(): void
    {
        $this->artisan('catalog:normalize-amazon-image-content')
            ->expectsOutputToContain('Pass --intake-run=<id> or --product=<id>.')
            ->assertFailed();
    }

    /**
     * @return array{0: Product, 1: ProductImage}
     */
    private function amazonProductWithImage(string $binary, bool $acquired): array
    {
        $merchant = Merchant::query()->create([
            'name' => 'Amazon India',
            'slug' => 'amazon-in-'.str()->random(8),
            'affiliate_network' => 'amazon_associates',
            'is_active' => true,
        ]);
        $product = Product::factory()->draft()->create([
            'name' => 'Editorial name',
            'description' => 'Editorial description',
            'taxonomy_classification_status' => TaxonomyClassificationStatus::AiAccepted,
            'taxonomy_content_fingerprint' => 'classification-before',
        ]);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://www.amazon.in/dp/'.str()->upper(str()->random(10)),
            'external_product_id' => str()->upper(str()->random(10)),
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);
        $path = 'products/'.$product->id.'/images/source.webp';
        Storage::disk('public')->put($path, $binary);
        $image = ProductImage::query()->create([
            'product_id' => $product->id,
            'disk' => 'public',
            'path' => $path,
            'alt_text' => $product->name,
            'is_primary' => true,
            'sort_order' => 0,
            'source_url' => 'https://m.media-amazon.com/images/I/411lYXWd-cL._SS1200_.jpg',
            'content_hash' => hash('sha256', 'source-truth'),
            'acquired_at' => $acquired ? now() : null,
        ]);

        return [$product->fresh(['affiliateLinks.merchant']), $image];
    }

    private function uniformBackgroundImage(): string
    {
        $image = imagecreatetruecolor(1200, 1200);
        imagefilledrectangle($image, 0, 0, 1199, 1199, imagecolorallocate($image, 250, 248, 245));
        imagefilledrectangle($image, 350, 350, 849, 849, imagecolorallocate($image, 25, 45, 70));

        return $this->encodeWebp($image);
    }

    private function nonUniformImage(): string
    {
        $image = imagecreatetruecolor(1200, 1200);
        imagefilledrectangle($image, 0, 0, 1199, 1199, imagecolorallocate($image, 245, 245, 245));
        imagefilledrectangle($image, 0, 0, 599, 1199, imagecolorallocate($image, 30, 30, 30));

        return $this->encodeWebp($image);
    }

    private function encodeWebp(\GdImage $image): string
    {
        ob_start();
        imagewebp($image, null, 90);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return $binary;
    }
}
