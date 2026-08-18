<?php

namespace Tests\Unit\Support;

use App\CatalogCandidate\Discovery\RetrievedCatalogCandidateSource;
use App\Support\CatalogCandidateSourceUrl;
use Tests\TestCase;

class CatalogCandidateSourceUrlTest extends TestCase
{
    public function test_it_normalizes_http_urls_and_rejects_non_http(): void
    {
        $this->assertSame('https://example.com/gifts', CatalogCandidateSourceUrl::normalize(' https://example.com/gifts '));
        $this->assertNull(CatalogCandidateSourceUrl::normalize('ftp://example.com/gifts'));
        $this->assertNull(CatalogCandidateSourceUrl::normalize('not-a-url'));
    }

    public function test_it_builds_a_stable_dedupe_key(): void
    {
        $this->assertSame(
            'https://example.com/gifts',
            CatalogCandidateSourceUrl::key('https://www.EXAMPLE.com/gifts/'),
        );
        $this->assertSame(
            CatalogCandidateSourceUrl::key('https://www.example.com/gifts'),
            CatalogCandidateSourceUrl::key('https://example.com/gifts/'),
        );
        $this->assertSame(
            CatalogCandidateSourceUrl::key('https://amazon.in/example?x=1'),
            CatalogCandidateSourceUrl::key('https://www.amazon.in/example?x=1#reviews'),
        );
    }

    public function test_it_does_not_collapse_material_url_differences(): void
    {
        $this->assertNotSame(
            CatalogCandidateSourceUrl::key('https://www.amazon.in/example?x=1'),
            CatalogCandidateSourceUrl::key('https://www.amazon.in/example?x=2'),
        );
        $this->assertNotSame(
            CatalogCandidateSourceUrl::key('https://www.amazon.in/example?x=1'),
            CatalogCandidateSourceUrl::key('https://www.amazon.in/other?x=1'),
        );
        $this->assertNotSame(
            CatalogCandidateSourceUrl::key('https://www.amazon.in/example?x=1'),
            CatalogCandidateSourceUrl::key('https://smile.amazon.in/example?x=1'),
        );
    }

    public function test_it_resolves_www_and_non_www_amazon_urls_to_the_corpus_url(): void
    {
        $corpus = [
            $this->source('https://www.amazon.in/example?x=1'),
        ];

        $this->assertSame(
            'https://www.amazon.in/example?x=1',
            CatalogCandidateSourceUrl::resolveAgainstCorpus('https://www.amazon.in/example?x=1', $corpus),
        );
        $this->assertSame(
            'https://www.amazon.in/example?x=1',
            CatalogCandidateSourceUrl::resolveAgainstCorpus('https://amazon.in/example?x=1', $corpus),
        );
        $this->assertSame(
            'https://www.amazon.in/example?x=1',
            CatalogCandidateSourceUrl::resolveAgainstCorpus('https://www.AMAZON.in/example/?x=1#offers', $corpus),
        );
    }

    public function test_it_does_not_remap_different_queries_paths_or_hosts(): void
    {
        $corpus = [
            $this->source('https://www.amazon.in/example?x=1'),
        ];

        $this->assertNull(
            CatalogCandidateSourceUrl::resolveAgainstCorpus('https://amazon.in/example?x=2', $corpus),
        );
        $this->assertNull(
            CatalogCandidateSourceUrl::resolveAgainstCorpus('https://amazon.in/other?x=1', $corpus),
        );
        $this->assertNull(
            CatalogCandidateSourceUrl::resolveAgainstCorpus('https://invented.example.com/example?x=1', $corpus),
        );
    }

    public function test_it_does_not_guess_when_normalized_keys_are_ambiguous(): void
    {
        $corpus = [
            $this->source('https://www.amazon.in/example?x=1'),
            $this->source('https://amazon.in/example?x=1'),
        ];

        $this->assertNull(
            CatalogCandidateSourceUrl::resolveAgainstCorpus('https://www.AMAZON.in/example/?x=1', $corpus),
        );
    }

    private function source(string $url): RetrievedCatalogCandidateSource
    {
        return new RetrievedCatalogCandidateSource(
            url: $url,
            title: 'Roundup',
            snippet: 'Gifts',
            sourceName: 'amazon.in',
            retrievedAt: now(),
        );
    }
}
