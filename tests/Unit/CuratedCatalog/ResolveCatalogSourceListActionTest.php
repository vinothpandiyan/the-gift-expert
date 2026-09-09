<?php

namespace Tests\Unit\CuratedCatalog;

use App\Actions\CuratedCatalog\ResolveCatalogSourceListAction;
use App\Actions\CuratedCatalog\ResolveCatalogSourceListMappingAction;
use App\CuratedCatalog\CuratedSourceListContext;
use App\Enums\CatalogSourceListKind;
use App\Models\CatalogSourceList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConfiguresCuratedCatalog;
use Tests\TestCase;

class ResolveCatalogSourceListActionTest extends TestCase
{
    use ConfiguresCuratedCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCuratedAmazonMerchant();
        $this->seedCuratedRelationships();
    }

    public function test_same_name_without_id_reuses_the_provisional_row(): void
    {
        $merchant = $this->configureCuratedAmazonMerchant();
        $action = app(ResolveCatalogSourceListAction::class);
        $context = new CuratedSourceListContext('Gifts for Husband', null, null, null);

        $first = $action->execute($merchant, $context);
        $second = $action->execute($merchant, $context);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, CatalogSourceList::query()->count());
        $this->assertNull($first->external_list_id);
        $this->assertSame('gifts-for-husband', $first->normalized_name);
    }

    public function test_identified_list_reconciles_an_existing_provisional_row(): void
    {
        $merchant = $this->configureCuratedAmazonMerchant();
        $action = app(ResolveCatalogSourceListAction::class);

        $provisional = $action->execute($merchant, new CuratedSourceListContext(
            'Gifts for Husband',
            null,
            null,
            null,
        ));

        $identified = $action->execute($merchant, new CuratedSourceListContext(
            'Gifts for Husband',
            '3HUSBANDLIST',
            'https://www.amazon.in/hz/wishlist/ls/3HUSBANDLIST',
            null,
        ));

        $this->assertTrue($provisional->is($identified));
        $this->assertSame(1, CatalogSourceList::query()->count());
        $this->assertSame('3HUSBANDLIST', $identified->external_list_id);
        $this->assertSame('id:3HUSBANDLIST', $identified->identity_key);
    }

    public function test_renamed_identified_list_updates_the_same_row(): void
    {
        $merchant = $this->configureCuratedAmazonMerchant();
        $action = app(ResolveCatalogSourceListAction::class);

        $original = $action->execute($merchant, new CuratedSourceListContext(
            'Gifts for Husband',
            '3HUSBANDLIST',
            'https://www.amazon.in/hz/wishlist/ls/3HUSBANDLIST',
            null,
        ));

        $renamed = $action->execute($merchant, new CuratedSourceListContext(
            'Husband Gift Ideas',
            '3HUSBANDLIST',
            'https://www.amazon.in/hz/wishlist/ls/3HUSBANDLIST',
            null,
        ));

        $this->assertTrue($original->is($renamed));
        $this->assertSame(1, CatalogSourceList::query()->count());
        $this->assertSame('Husband Gift Ideas', $renamed->name);
        $this->assertSame('husband-gift-ideas', $renamed->normalized_name);
        $this->assertSame('3HUSBANDLIST', $renamed->external_list_id);
    }

    public function test_external_list_id_wins_over_name_mapping(): void
    {
        config([
            'curated_catalog.source_lists.amazon-in.by_external_list_id.3CUSTOM' => [
                'kind' => 'quarterly_archive',
            ],
        ]);

        $mapping = app(ResolveCatalogSourceListMappingAction::class)->execute(
            'amazon-in',
            new CuratedSourceListContext('Gifts for Husband', '3CUSTOM', null, 'recipient_hint'),
        );

        $this->assertSame(CatalogSourceListKind::QuarterlyArchive->value, $mapping->kind);
        $this->assertNull($mapping->relationshipSlug);
        $this->assertTrue($mapping->isMapped);
        $this->assertSame('external_list_id', $mapping->matchedBy);
    }
}
