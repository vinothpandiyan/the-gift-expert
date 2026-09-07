<?php

namespace Tests\Unit\Actions;

use App\Actions\Discovery\NormalizeDiscoveryFilterStateAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyDimension;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Models\TaxonomyApplicabilityRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NormalizeDiscoveryFilterStateActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_url_load_drops_semantically_invalid_occasion_and_keeps_relationship(): void
    {
        $this->bridalShowerRules();

        $normalized = app(NormalizeDiscoveryFilterStateAction::class)->execute(
            DiscoveryListingContext::forGiftIdeas(),
            new DiscoveryListingQueryState(
                occasionSlugs: ['bridal-shower'],
                relationshipSlugs: ['husband'],
            ),
        );

        $this->assertSame(['husband'], $normalized->relationshipSlugs);
        $this->assertSame([], $normalized->occasionSlugs);
    }

    public function test_preferred_dimension_is_last_write_wins(): void
    {
        $this->bridalShowerRules();

        $normalized = app(NormalizeDiscoveryFilterStateAction::class)->execute(
            DiscoveryListingContext::forGiftIdeas(),
            new DiscoveryListingQueryState(
                occasionSlugs: ['bridal-shower'],
                relationshipSlugs: ['husband'],
            ),
            'occasion',
        );

        $this->assertSame(['bridal-shower'], $normalized->occasionSlugs);
        $this->assertSame([], $normalized->relationshipSlugs);
    }

    public function test_page_context_still_invalidates_a_preferred_value(): void
    {
        [$husband] = $this->bridalShowerRules();

        $normalized = app(NormalizeDiscoveryFilterStateAction::class)->execute(
            DiscoveryListingContext::forRelationship($husband),
            new DiscoveryListingQueryState(occasionSlugs: ['bridal-shower']),
            'occasion',
        );

        $this->assertSame([], $normalized->occasionSlugs);
    }

    public function test_unknown_and_inactive_slugs_are_dropped(): void
    {
        $this->relationship('Husband');
        $this->occasion('Birthday');
        $inactive = $this->occasion('Festival');
        $inactive->update(['is_active' => false]);

        $normalized = app(NormalizeDiscoveryFilterStateAction::class)->execute(
            DiscoveryListingContext::forGiftIdeas(),
            new DiscoveryListingQueryState(
                occasionSlugs: ['birthday', 'festival', 'not-real'],
                relationshipSlugs: ['husband'],
            ),
        );

        $this->assertSame(['birthday'], $normalized->occasionSlugs);
        $this->assertSame(['husband'], $normalized->relationshipSlugs);
    }

    /**
     * @return array{0: Relationship, 1: Occasion}
     */
    private function bridalShowerRules(): array
    {
        $husband = $this->relationship('Husband');
        $sister = $this->relationship('Sister');
        $bridalShower = $this->occasion('Bridal Shower');

        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => TaxonomyDimension::Occasion,
            'source_id' => $bridalShower->id,
            'target_dimension' => TaxonomyDimension::Relationship,
            'target_id' => $sister->id,
            'effect' => TaxonomyApplicabilityEffect::Allow,
            'reason' => 'test',
            'is_active' => true,
        ]);

        return [$husband, $bridalShower];
    }

    private function relationship(string $name): Relationship
    {
        return Relationship::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }

    private function occasion(string $name): Occasion
    {
        return Occasion::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }
}
