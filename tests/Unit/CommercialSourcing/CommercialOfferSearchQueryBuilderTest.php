<?php

namespace Tests\Unit\CommercialSourcing;

use App\CommercialSourcing\CommercialOfferSearchQueryBuilder;
use App\Models\CatalogCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialOfferSearchQueryBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_buy_and_online_queries_from_the_candidate_title(): void
    {
        $candidate = CatalogCandidate::factory()->create([
            'title' => 'French press',
            'summary' => null,
        ]);

        $queries = app(CommercialOfferSearchQueryBuilder::class)->queries($candidate, 'IN');

        $this->assertSame([
            'French press buy India',
            'French press online India',
        ], $queries);
    }

    public function test_it_does_not_use_gift_research_phrasing(): void
    {
        $candidate = CatalogCandidate::factory()->create(['title' => 'French press']);
        $queries = implode(' ', app(CommercialOfferSearchQueryBuilder::class)->queries($candidate, 'IN'));

        $this->assertStringNotContainsString('trending', $queries);
        $this->assertStringNotContainsString('unique', $queries);
        $this->assertStringNotContainsString('thoughtful', $queries);
    }
}
