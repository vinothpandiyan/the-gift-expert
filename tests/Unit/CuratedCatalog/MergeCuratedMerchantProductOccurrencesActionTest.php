<?php

namespace Tests\Unit\CuratedCatalog;

use App\Actions\CuratedCatalog\MergeCuratedMerchantProductOccurrencesAction;
use App\Actions\CuratedCatalog\ParseCuratedMerchantProductsAction;
use App\CuratedCatalog\CuratedMerchantProductInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\TestCase;

class MergeCuratedMerchantProductOccurrencesActionTest extends TestCase
{
    use ConfiguresCuratedCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCuratedAmazonMerchant();
    }

    public function test_same_asin_across_lists_becomes_one_actionable_product(): void
    {
        $occurrences = array_merge(
            $this->parseList('Gifts for Boyfriend', 'B0ABCDEFGH', 'Boyfriend title', '999.00'),
            $this->parseList('Gifts for Husband', 'B0ABCDEFGH', 'Husband title', '1299.00'),
            $this->parseList('Gifts for Brother', 'B0ABCDEFGH', 'Brother title', '1499.00'),
        );

        $merged = app(MergeCuratedMerchantProductOccurrencesAction::class)->execute($occurrences);

        $this->assertCount(1, $merged);
        $this->assertSame('B0ABCDEFGH', $merged[0]->externalProductId);
        $this->assertSame(3, $merged[0]->occurrenceCount());
        $this->assertSame(3, $merged[0]->sourceListCount());
        $this->assertSame(['Gifts for Boyfriend', 'Gifts for Husband', 'Gifts for Brother'], $merged[0]->sourceListNames());
        $this->assertSame('Brother title', $merged[0]->input->title);
        $this->assertSame('1499.00', $merged[0]->input->priceAmount);
        $this->assertContains('title', $merged[0]->conflicts);
        $this->assertContains('price', $merged[0]->conflicts);
    }

    public function test_canonical_product_url_is_preferred_over_wishlist_tracking_url(): void
    {
        $canonical = $this->parseList('Gifts for Husband', 'B0ABCDEFGH', 'Gift', '1299.00')[0];
        $tracking = $this->parseList(
            'Gifts for Boyfriend',
            'B0ABCDEFGH',
            'Gift',
            '1299.00',
            'https://www.amazon.in/dp/B0ABCDEFGH?coliid=abc&colid=xyz',
        )[0];

        $merged = app(MergeCuratedMerchantProductOccurrencesAction::class)->execute([$tracking, $canonical]);

        $this->assertSame('https://www.amazon.in/dp/B0ABCDEFGH', $merged[0]->input->sourceUrl);
        $this->assertContains('source_url', $merged[0]->conflicts);
    }

    public function test_later_valid_price_and_availability_win(): void
    {
        $first = $this->parseList('Gifts for Husband', 'B0ABCDEFGH', 'Gift', '999.00', availability: 'in_stock')[0];
        $second = $this->parseList('Gifts for Boyfriend', 'B0ABCDEFGH', 'Gift', '1599.00', availability: 'out_of_stock')[0];

        $merged = app(MergeCuratedMerchantProductOccurrencesAction::class)->execute([$first, $second]);

        $this->assertSame('1599.00', $merged[0]->input->priceAmount);
        $this->assertSame('out_of_stock', $merged[0]->input->availability);
        $this->assertContains('price', $merged[0]->conflicts);
        $this->assertContains('availability', $merged[0]->conflicts);
    }

    /**
     * @return list<CuratedMerchantProductInput>
     */
    private function parseList(
        string $listName,
        string $asin,
        string $title,
        string $price,
        ?string $sourceUrl = null,
        string $availability = 'in_stock',
    ): array {
        $rows = app(ParseCuratedMerchantProductsAction::class)->execute($this->curatedWishlistPayload(
            $listName,
            [$this->curatedWishlistItem($asin, [
                'title' => $title,
                'price_amount' => $price,
                'source_url' => $sourceUrl ?? 'https://www.amazon.in/dp/'.$asin,
                'availability' => $availability,
            ])],
        ));

        return array_values(array_filter(
            $rows,
            fn (mixed $row): bool => $row instanceof CuratedMerchantProductInput,
        ));
    }
}
