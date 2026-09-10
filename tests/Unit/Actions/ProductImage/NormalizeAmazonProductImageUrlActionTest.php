<?php

namespace Tests\Unit\Actions\ProductImage;

use App\Actions\ProductImage\NormalizeAmazonProductImageUrlAction;
use App\Models\Merchant;
use Tests\TestCase;

class NormalizeAmazonProductImageUrlActionTest extends TestCase
{
    public function test_it_rewrites_wrapped_low_resolution_amazon_thumbnails(): void
    {
        $result = app(NormalizeAmazonProductImageUrlAction::class)->execute(
            'https://m.media-amazon.com/images/W/BW_MEDIAX_AVIF_MEASUREMENT_1306696-T3/images/I/411lYXWd-cL._SS135_.jpg',
        );

        $this->assertTrue($result->isAmazon);
        $this->assertTrue($result->changed);
        $this->assertSame(135, $result->detectedLongEdge);
        $this->assertSame(1500, $result->requestedLongEdge);
        $this->assertSame(
            'https://m.media-amazon.com/images/I/411lYXWd-cL._SL1500_.jpg',
            $result->url,
        );
    }

    public function test_it_rewrites_simple_ss_and_ac_ul_modifiers(): void
    {
        $action = app(NormalizeAmazonProductImageUrlAction::class);

        $ss = $action->execute('https://m.media-amazon.com/images/I/411lYXWd-cL._SS135_.jpg');
        $this->assertSame('https://m.media-amazon.com/images/I/411lYXWd-cL._SL1500_.jpg', $ss->url);

        $ul = $action->execute('https://images-na.ssl-images-amazon.com/images/I/51abcDEFgH._AC_UL320_.jpg');
        $this->assertTrue($ul->isAmazon);
        $this->assertSame('https://m.media-amazon.com/images/I/51abcDEFgH._SL1500_.jpg', $ul->url);
    }

    public function test_it_preserves_a_high_resolution_long_edge_rendition(): void
    {
        $result = app(NormalizeAmazonProductImageUrlAction::class)->execute(
            'https://m.media-amazon.com/images/I/411lYXWd-cL._AC_SL1500_.jpg',
        );

        $this->assertTrue($result->isAmazon);
        $this->assertTrue($result->changed);
        $this->assertSame(1500, $result->detectedLongEdge);
        $this->assertSame(1500, $result->requestedLongEdge);
        $this->assertSame(
            'https://m.media-amazon.com/images/I/411lYXWd-cL._SL1500_.jpg',
            $result->url,
        );
    }

    public function test_it_replaces_square_renditions_but_keeps_high_resolution_ux_urls(): void
    {
        $action = app(NormalizeAmazonProductImageUrlAction::class);

        $ss = $action->execute('https://m.media-amazon.com/images/I/411lYXWd-cL._SS1200_.jpg');
        $this->assertTrue($ss->changed);
        $this->assertSame('https://m.media-amazon.com/images/I/411lYXWd-cL._SL1500_.jpg', $ss->url);

        $ux = $action->execute('https://m.media-amazon.com/images/I/411lYXWd-cL._UX1200_.jpg');
        $this->assertFalse($ux->changed);
        $this->assertSame('https://m.media-amazon.com/images/I/411lYXWd-cL._UX1200_.jpg', $ux->url);
    }

    public function test_it_is_idempotent_for_canonical_high_quality_urls(): void
    {
        $action = app(NormalizeAmazonProductImageUrlAction::class);
        $first = $action->execute('https://m.media-amazon.com/images/I/411lYXWd-cL._SS135_.jpg');
        $second = $action->execute($first->url);

        $this->assertTrue($first->changed);
        $this->assertFalse($second->changed);
        $this->assertSame($first->url, $second->url);
        $this->assertSame(
            'https://m.media-amazon.com/images/I/411lYXWd-cL._SL1500_.jpg',
            $first->url,
        );
    }

    public function test_it_does_not_rewrite_non_amazon_or_malformed_urls(): void
    {
        $action = app(NormalizeAmazonProductImageUrlAction::class);

        $flipkart = $action->execute('https://rukminim2.flixcart.com/image/416/416/xif0q/foo.jpeg');
        $this->assertFalse($flipkart->isAmazon);
        $this->assertFalse($flipkart->changed);
        $this->assertSame('https://rukminim2.flixcart.com/image/416/416/xif0q/foo.jpeg', $flipkart->url);

        $malformed = $action->execute('https://m.media-amazon.com/images/G/01/not-an-image-id.jpg');
        $this->assertFalse($malformed->isAmazon);
        $this->assertFalse($malformed->changed);

        $notUrl = $action->execute('not-a-url');
        $this->assertFalse($notUrl->isAmazon);
        $this->assertSame('not-a-url', $notUrl->url);
    }

    public function test_it_does_not_rewrite_amazon_urls_for_non_amazon_merchants(): void
    {
        $flipkart = new Merchant([
            'name' => 'Flipkart',
            'slug' => 'flipkart',
            'affiliate_network' => 'flipkart',
        ]);

        $result = app(NormalizeAmazonProductImageUrlAction::class)->execute(
            'https://m.media-amazon.com/images/I/411lYXWd-cL._SS135_.jpg',
            $flipkart,
        );

        $this->assertFalse($result->isAmazon);
        $this->assertFalse($result->changed);
        $this->assertSame(
            'https://m.media-amazon.com/images/I/411lYXWd-cL._SS135_.jpg',
            $result->url,
        );
    }

    public function test_unmodified_amazon_url_without_modifier_requests_canonical_long_edge(): void
    {
        $result = app(NormalizeAmazonProductImageUrlAction::class)->execute(
            'https://m.media-amazon.com/images/I/411lYXWd-cL.jpg',
        );

        $this->assertTrue($result->changed);
        $this->assertNull($result->detectedLongEdge);
        $this->assertSame(
            'https://m.media-amazon.com/images/I/411lYXWd-cL._SL1500_.jpg',
            $result->url,
        );
    }
}
