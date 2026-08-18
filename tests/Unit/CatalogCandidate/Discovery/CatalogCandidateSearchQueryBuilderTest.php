<?php

namespace Tests\Unit\CatalogCandidate\Discovery;

use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Discovery\CatalogCandidateSearchQueryBuilder;
use Tests\TestCase;

class CatalogCandidateSearchQueryBuilderTest extends TestCase
{
    public function test_it_builds_deterministic_generic_queries(): void
    {
        $builder = app(CatalogCandidateSearchQueryBuilder::class);
        $brief = CatalogCandidateResearchBrief::from(
            '  Find useful gift ideas for colleagues  ',
            'IN',
        );

        $first = $builder->queries($brief);
        $second = $builder->queries($brief);

        $this->assertSame($first, $second);
        $this->assertCount(3, $first);
        $this->assertSame([
            'Find useful gift ideas for colleagues India',
            'trending gift ideas for colleagues India',
            'unique gift ideas for colleagues India',
        ], $first);
    }

    public function test_it_builds_natural_variants_for_live_style_briefs(): void
    {
        $queries = app(CatalogCandidateSearchQueryBuilder::class)->queries(
            CatalogCandidateResearchBrief::from(
                'Find useful birthday gift ideas for husbands in India',
                'IN',
            ),
        );

        $this->assertSame([
            'Find useful birthday gift ideas for husbands in India',
            'trending birthday gift ideas for husbands in India',
            'unique birthday gift ideas for husbands in India',
        ], $queries);
    }

    public function test_it_does_not_append_a_market_label_already_in_the_brief(): void
    {
        $queries = app(CatalogCandidateSearchQueryBuilder::class)->queries(
            CatalogCandidateResearchBrief::from('gift ideas in India', 'IN'),
        );

        $this->assertSame([
            'gift ideas in India',
            'gift ideas in India trending',
            'gift ideas in India unique',
        ], $queries);
    }

    public function test_it_caps_queries_from_config(): void
    {
        config(['catalog_candidate_discovery.search.max_queries_per_brief' => 1]);

        $queries = app(CatalogCandidateSearchQueryBuilder::class)->queries(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );

        $this->assertCount(1, $queries);
        $this->assertSame(['thoughtful gifts India'], $queries);
    }

    public function test_it_hard_caps_queries_at_three(): void
    {
        config(['catalog_candidate_discovery.search.max_queries_per_brief' => 10]);

        $queries = app(CatalogCandidateSearchQueryBuilder::class)->queries(
            CatalogCandidateResearchBrief::from('thoughtful gifts', 'IN'),
        );

        $this->assertCount(3, $queries);
    }

    public function test_it_appends_source_categories_as_hints_without_extra_queries(): void
    {
        $queries = app(CatalogCandidateSearchQueryBuilder::class)->queries(
            CatalogCandidateResearchBrief::from(
                'thoughtful gifts',
                'IN',
                sourceCategories: ['editorial', 'community'],
            ),
        );

        $this->assertCount(3, $queries);
        $this->assertSame([
            'thoughtful gifts India editorial community',
            'thoughtful gifts trending India editorial community',
            'thoughtful gifts unique India editorial community',
        ], $queries);
    }

    public function test_it_collapses_whitespace_and_caps_query_length(): void
    {
        config(['catalog_candidate_discovery.search.max_query_length' => 24]);

        $queries = app(CatalogCandidateSearchQueryBuilder::class)->queries(
            CatalogCandidateResearchBrief::from("thoughtful\n\n  gifts   for   colleagues", 'IN'),
        );

        $this->assertSame(24, mb_strlen($queries[0]));
        $this->assertSame('thoughtful gifts for col', $queries[0]);
    }

    public function test_the_builder_does_not_hardcode_recipient_or_occasion_terms(): void
    {
        $source = (string) file_get_contents(app_path('CatalogCandidate/Discovery/CatalogCandidateSearchQueryBuilder.php'));

        $this->assertStringNotContainsStringIgnoringCase('husband', $source);
        $this->assertStringNotContainsStringIgnoringCase('birthday', $source);
        $this->assertStringNotContainsStringIgnoringCase('India', $source);
    }
}
