<?php

namespace Tests\Unit\CuratedCatalog;

use App\Actions\CuratedCatalog\ResolveCatalogSourceListMappingAction;
use App\CuratedCatalog\CuratedSourceListContext;
use App\Enums\CatalogSourceListKind;
use Tests\TestCase;

class ResolveCatalogSourceListMappingActionTest extends TestCase
{
    public function test_husband_maps_to_a_relationship_hint(): void
    {
        $mapping = app(ResolveCatalogSourceListMappingAction::class)->execute(
            'amazon-in',
            new CuratedSourceListContext('Gifts for Husband', null, null, 'unknown'),
        );

        $this->assertTrue($mapping->isMapped);
        $this->assertSame(CatalogSourceListKind::RecipientHint->value, $mapping->kind);
        $this->assertSame('husband', $mapping->relationshipSlug);
    }

    public function test_quarterly_archive_does_not_map_a_relationship_hint(): void
    {
        $mapping = app(ResolveCatalogSourceListMappingAction::class)->execute(
            'amazon-in',
            new CuratedSourceListContext('01 - Gift Ideas - Q1 2026', null, null, null),
        );

        $this->assertTrue($mapping->isMapped);
        $this->assertSame(CatalogSourceListKind::QuarterlyArchive->value, $mapping->kind);
        $this->assertNull($mapping->relationshipSlug);
    }

    public function test_unclassified_inbox_does_not_map_a_relationship_hint(): void
    {
        $mapping = app(ResolveCatalogSourceListMappingAction::class)->execute(
            'amazon-in',
            new CuratedSourceListContext('00 - Unclassified Gift Ideas', null, null, null),
        );

        $this->assertTrue($mapping->isMapped);
        $this->assertSame(CatalogSourceListKind::UnclassifiedInbox->value, $mapping->kind);
        $this->assertNull($mapping->relationshipSlug);
    }

    public function test_unknown_names_are_flagged_unmapped(): void
    {
        $mapping = app(ResolveCatalogSourceListMappingAction::class)->execute(
            'amazon-in',
            new CuratedSourceListContext('Random Birthday Stuff', null, null, null),
        );

        $this->assertFalse($mapping->isMapped);
        $this->assertSame(CatalogSourceListKind::Unknown->value, $mapping->kind);
        $this->assertNull($mapping->relationshipSlug);
        $this->assertSame('unmapped', $mapping->matchedBy);
    }

    public function test_client_provided_list_kind_is_not_trusted(): void
    {
        $mapping = app(ResolveCatalogSourceListMappingAction::class)->execute(
            'amazon-in',
            new CuratedSourceListContext('00 - Unclassified Gift Ideas', null, null, 'recipient_hint'),
        );

        $this->assertSame(CatalogSourceListKind::UnclassifiedInbox->value, $mapping->kind);
        $this->assertNull($mapping->relationshipSlug);
    }
}
