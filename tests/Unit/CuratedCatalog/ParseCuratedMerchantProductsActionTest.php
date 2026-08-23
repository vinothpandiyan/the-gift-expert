<?php

namespace Tests\Unit\CuratedCatalog;

use App\Actions\CuratedCatalog\ParseCuratedMerchantProductsAction;
use App\CuratedCatalog\CuratedMerchantProductInput;
use App\CuratedCatalog\CuratedProductInputError;
use App\CuratedCatalog\CuratedProductIntakeParseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\TestCase;

class ParseCuratedMerchantProductsActionTest extends TestCase
{
    use ConfiguresCuratedCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCuratedAmazonMerchant();
    }

    public function test_it_parses_a_valid_payload(): void
    {
        $rows = app(ParseCuratedMerchantProductsAction::class)->execute($this->curatedPayload());

        $this->assertCount(1, $rows);
        $this->assertInstanceOf(CuratedMerchantProductInput::class, $rows[0]);
        $this->assertSame('B0ABCDEFGH', $rows[0]->externalProductId);
        $this->assertSame('1299.00', $rows[0]->priceAmount);
        $this->assertSame('https://m.media-amazon.com/images/I/example.jpg', $rows[0]->sourceImageUrl);
        $this->assertSame('men', $rows[0]->curationGroup);
    }

    public function test_malformed_json_is_fatal(): void
    {
        $this->expectException(CuratedProductIntakeParseException::class);

        app(ParseCuratedMerchantProductsAction::class)->execute('{not-json');
    }

    public function test_wrong_version_is_fatal(): void
    {
        $this->expectException(CuratedProductIntakeParseException::class);

        app(ParseCuratedMerchantProductsAction::class)->execute($this->curatedPayload([
            'version' => 2,
        ]));
    }

    public function test_unknown_root_keys_are_rejected(): void
    {
        $this->expectException(CuratedProductIntakeParseException::class);

        app(ParseCuratedMerchantProductsAction::class)->execute($this->curatedPayload([
            'extra' => true,
        ]));
    }

    public function test_unknown_item_keys_are_rejected(): void
    {
        $rows = app(ParseCuratedMerchantProductsAction::class)->execute($this->curatedPayload([
            'items' => [
                [
                    'external_product_id' => 'B0ABCDEFGH',
                    'source_url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
                    'title' => 'Gift',
                    'merchant' => 'amazon-in',
                ],
            ],
        ]));

        $this->assertInstanceOf(CuratedProductInputError::class, $rows[0]);
        $this->assertSame('unknown_item_keys', $rows[0]->code);
    }

    public function test_duplicate_asin_in_payload_yields_two_rows(): void
    {
        $rows = app(ParseCuratedMerchantProductsAction::class)->execute($this->curatedPayload([
            'items' => [
                [
                    'external_product_id' => 'B0ABCDEFGH',
                    'source_url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
                    'title' => 'First',
                ],
                [
                    'external_product_id' => 'B0ABCDEFGH',
                    'source_url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
                    'title' => 'Second',
                ],
            ],
        ]));

        $this->assertCount(2, $rows);
    }

    public function test_invalid_asin_is_rejected(): void
    {
        $rows = app(ParseCuratedMerchantProductsAction::class)->execute($this->curatedPayload([
            'items' => [[
                'external_product_id' => 'BAD',
                'source_url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
                'title' => 'Gift',
            ]],
        ]));

        $this->assertInstanceOf(CuratedProductInputError::class, $rows[0]);
        $this->assertSame('invalid_asin', $rows[0]->code);
    }

    public function test_asin_url_mismatch_is_rejected(): void
    {
        $rows = app(ParseCuratedMerchantProductsAction::class)->execute($this->curatedPayload([
            'items' => [[
                'external_product_id' => 'B0ABCDEFGH',
                'source_url' => 'https://www.amazon.in/dp/B0ZZZZZZZZ',
                'title' => 'Gift',
            ]],
        ]));

        $this->assertInstanceOf(CuratedProductInputError::class, $rows[0]);
        $this->assertSame('asin_url_mismatch', $rows[0]->code);
    }

    public function test_invalid_host_is_rejected(): void
    {
        $rows = app(ParseCuratedMerchantProductsAction::class)->execute($this->curatedPayload([
            'items' => [[
                'external_product_id' => 'B0ABCDEFGH',
                'source_url' => 'https://www.example.com/dp/B0ABCDEFGH',
                'title' => 'Gift',
            ]],
        ]));

        $this->assertInstanceOf(CuratedProductInputError::class, $rows[0]);
        $this->assertSame('invalid_host', $rows[0]->code);
    }

    public function test_search_url_is_rejected(): void
    {
        $rows = app(ParseCuratedMerchantProductsAction::class)->execute($this->curatedPayload([
            'items' => [[
                'external_product_id' => 'B0ABCDEFGH',
                'source_url' => 'https://www.amazon.in/s?k=wallet',
                'title' => 'Gift',
            ]],
        ]));

        $this->assertInstanceOf(CuratedProductInputError::class, $rows[0]);
        $this->assertSame('denied_source_url', $rows[0]->code);
    }

    public function test_missing_title_is_rejected(): void
    {
        $rows = app(ParseCuratedMerchantProductsAction::class)->execute($this->curatedPayload([
            'items' => [[
                'external_product_id' => 'B0ABCDEFGH',
                'source_url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
            ]],
        ]));

        $this->assertInstanceOf(CuratedProductInputError::class, $rows[0]);
        $this->assertSame('missing_title', $rows[0]->code);
    }

    public function test_missing_price_is_allowed(): void
    {
        $rows = app(ParseCuratedMerchantProductsAction::class)->execute($this->curatedPayload([
            'items' => [[
                'external_product_id' => 'B0ABCDEFGH',
                'source_url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
                'title' => 'Gift',
            ]],
        ]));

        $this->assertInstanceOf(CuratedMerchantProductInput::class, $rows[0]);
        $this->assertNull($rows[0]->priceAmount);
    }

    public function test_invalid_price_is_rejected(): void
    {
        $rows = app(ParseCuratedMerchantProductsAction::class)->execute($this->curatedPayload([
            'items' => [[
                'external_product_id' => 'B0ABCDEFGH',
                'source_url' => 'https://www.amazon.in/dp/B0ABCDEFGH',
                'title' => 'Gift',
                'price_amount' => 'not-a-price',
            ]],
        ]));

        $this->assertInstanceOf(CuratedProductInputError::class, $rows[0]);
        $this->assertSame('invalid_price', $rows[0]->code);
    }

    public function test_source_image_url_is_retained_as_metadata_only(): void
    {
        $rows = app(ParseCuratedMerchantProductsAction::class)->execute($this->curatedPayload());

        $this->assertInstanceOf(CuratedMerchantProductInput::class, $rows[0]);
        $this->assertSame('https://m.media-amazon.com/images/I/example.jpg', $rows[0]->sourceImageUrl);
        $this->assertSame('https://m.media-amazon.com/images/I/example.jpg', $rows[0]->sourcePayload['source_image_url']);
    }
}
