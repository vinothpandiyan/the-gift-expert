<?php

namespace Tests\Unit\CatalogCandidate\Discovery;

use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Discovery\GroundedCandidateSynthesisPrompt;
use App\CatalogCandidate\Discovery\RetrievedCatalogCandidateSource;
use Tests\TestCase;

class GroundedCandidateSynthesisPromptTest extends TestCase
{
    public function test_it_builds_a_bounded_payload_with_exact_source_urls(): void
    {
        $brief = CatalogCandidateResearchBrief::from('Find gift ideas for coffee lovers in India', 'IN', 2);
        $sources = [
            new RetrievedCatalogCandidateSource(
                url: 'https://www.example.com/coffee-gifts',
                title: 'Best coffee gifts for home brewing',
                snippet: 'French press',
                sourceName: 'example.com',
                retrievedAt: now(),
            ),
            new RetrievedCatalogCandidateSource(
                url: 'https://www.reddit.com/r/coffee/comments/gifts',
                title: 'Reddit thread',
                snippet: 'Cold brew kit',
                sourceName: 'reddit.com',
                retrievedAt: now(),
            ),
        ];

        $prompt = app(GroundedCandidateSynthesisPrompt::class);
        $messages = $prompt->messages($brief, ['Find gift ideas for coffee lovers in India'], [$sources[0]]);

        $this->assertStringContainsString('Find gift ideas for coffee lovers in India', $messages['user']);
        $this->assertStringContainsString('https://www.example.com/coffee-gifts', $messages['user']);
        $this->assertStringNotContainsString('https://www.reddit.com/r/coffee/comments/gifts', $messages['user']);
        $this->assertStringNotContainsString('catalog_candidates', $messages['user']);
        $this->assertStringNotContainsString('products', $messages['user']);
        $this->assertStringContainsString('concrete', $messages['system']);
        $this->assertStringContainsString('Do not repeat article titles', $messages['system']);
    }

    public function test_the_schema_does_not_expose_catalog_or_merchant_fields(): void
    {
        $schema = json_encode(app(GroundedCandidateSynthesisPrompt::class)->jsonSchema());

        $this->assertIsString($schema);
        $this->assertStringNotContainsString('source_type', $schema);
        $this->assertStringNotContainsString('source_name', $schema);
        $this->assertStringNotContainsString('priority', $schema);
        $this->assertStringNotContainsString('product', $schema);
        $this->assertStringNotContainsString('merchant', $schema);
        $this->assertStringNotContainsString('taxonomy', $schema);
        $this->assertStringNotContainsString('affiliate', $schema);
        $this->assertStringNotContainsString('confidence', $schema);
        $this->assertStringNotContainsString('image', $schema);
        $this->assertStringContainsString('candidates', $schema);
        $this->assertStringContainsString('source_url', $schema);
    }
}
