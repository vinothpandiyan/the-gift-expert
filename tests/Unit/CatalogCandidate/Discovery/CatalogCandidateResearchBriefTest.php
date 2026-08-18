<?php

namespace Tests\Unit\CatalogCandidate\Discovery;

use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use InvalidArgumentException;
use Tests\TestCase;

class CatalogCandidateResearchBriefTest extends TestCase
{
    public function test_it_trims_the_brief_and_applies_defaults(): void
    {
        $brief = CatalogCandidateResearchBrief::from('  Find useful birthday gift ideas for husbands in India  ');

        $this->assertSame('Find useful birthday gift ideas for husbands in India', $brief->brief);
        $this->assertSame('IN', $brief->market);
        $this->assertSame(10, $brief->maxCandidates);
        $this->assertSame(30, $brief->freshnessDays);
        $this->assertSame([], $brief->sourceCategories);
    }

    public function test_blank_briefs_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A research brief is required.');

        CatalogCandidateResearchBrief::from('   ');
    }

    public function test_market_is_normalized_to_uppercase_iso_code(): void
    {
        $brief = CatalogCandidateResearchBrief::from('gifts', ' in ');

        $this->assertSame('IN', $brief->market);
    }

    public function test_invalid_markets_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Market must be a 2-letter ISO country code.');

        CatalogCandidateResearchBrief::from('gifts', 'India');
    }

    public function test_max_candidates_is_hard_capped_from_config(): void
    {
        config(['catalog_candidate_discovery.max_candidates' => 20]);

        $brief = CatalogCandidateResearchBrief::from('gifts', maxCandidates: '100');

        $this->assertSame(20, $brief->maxCandidates);
    }

    public function test_non_positive_max_candidates_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum candidates must be a positive integer.');

        CatalogCandidateResearchBrief::from('gifts', maxCandidates: 0);
    }

    public function test_freshness_days_are_capped_from_config(): void
    {
        config(['catalog_candidate_discovery.max_freshness_days' => 60]);

        $brief = CatalogCandidateResearchBrief::from('gifts', freshnessDays: '90');

        $this->assertSame(60, $brief->freshnessDays);
    }

    public function test_non_positive_freshness_days_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Freshness days must be a positive integer.');

        CatalogCandidateResearchBrief::from('gifts', freshnessDays: 0);
    }

    public function test_source_categories_are_normalized_string_hints(): void
    {
        $brief = CatalogCandidateResearchBrief::from(
            'gifts',
            sourceCategories: [' Editorial ', 'COMMUNITY', 'editorial', ''],
        );

        $this->assertSame(['editorial', 'community'], $brief->sourceCategories);
    }
}
