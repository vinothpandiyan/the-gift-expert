<?php

namespace Tests\Feature\Seeders;

use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyDimension;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Occasion;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\TaxonomyApplicabilityRule;
use Database\Seeders\CategorySeeder;
use Database\Seeders\GiftTypeSeeder;
use Database\Seeders\OccasionSeeder;
use Database\Seeders\RecipientTypeSeeder;
use Database\Seeders\RelationshipSeeder;
use Database\Seeders\TaxonomyApplicabilityRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxonomyApplicabilityRuleSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<class-string>
     */
    private array $seeders = [
        OccasionSeeder::class,
        RelationshipSeeder::class,
        RecipientTypeSeeder::class,
        GiftTypeSeeder::class,
        CategorySeeder::class,
        TaxonomyApplicabilityRuleSeeder::class,
    ];

    public function test_it_seeds_high_confidence_semantic_rules(): void
    {
        $this->seed($this->seeders);

        $this->assertSame(36, TaxonomyApplicabilityRule::query()->count());
        $this->assertTrue($this->hasRule('relationship', 'husband', 'occasion', 'baby-shower', TaxonomyApplicabilityEffect::Exclude));
        $this->assertTrue($this->hasRule('occasion', 'raksha-bandhan', 'relationship', 'brother', TaxonomyApplicabilityEffect::Allow));
        $this->assertTrue($this->hasRule('occasion', 'raksha-bandhan', 'relationship', 'sister', TaxonomyApplicabilityEffect::Allow));
        $this->assertTrue($this->hasRule('relationship', 'husband', 'gift_type', 'return-gifts', TaxonomyApplicabilityEffect::Exclude));
        $this->assertFalse($this->hasRule('recipient_type', 'kids', 'gift_type', 'return-gifts', TaxonomyApplicabilityEffect::Exclude));
        $this->assertTrue($this->hasRule('recipient_type', 'kids', 'occasion', 'retirement', TaxonomyApplicabilityEffect::Exclude));
        $this->assertTrue($this->hasRule('occasion', 'valentines-day', 'relationship', 'husband', TaxonomyApplicabilityEffect::Allow));
        $this->assertTrue($this->hasRule('occasion', 'bridal-shower', 'relationship', 'sister', TaxonomyApplicabilityEffect::Allow));
    }

    public function test_it_does_not_seed_rules_against_the_legacy_personalized_category(): void
    {
        $this->seed($this->seeders);

        $legacyId = Category::query()->where('slug', 'personalized-gifts')->value('id');

        $this->assertNotNull($legacyId);
        $this->assertFalse(
            TaxonomyApplicabilityRule::query()
                ->where(function ($query): void {
                    $query->where('source_dimension', TaxonomyDimension::Category)
                        ->orWhere('target_dimension', TaxonomyDimension::Category);
                })
                ->exists(),
        );
        $this->assertTrue(GiftType::query()->where('slug', 'personalized-gifts')->where('is_active', true)->exists());
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed($this->seeders);
        $count = TaxonomyApplicabilityRule::query()->count();

        $this->seed($this->seeders);

        $this->assertSame($count, TaxonomyApplicabilityRule::query()->count());
    }

    private function hasRule(
        string $sourceDimension,
        string $sourceSlug,
        string $targetDimension,
        string $targetSlug,
        TaxonomyApplicabilityEffect $effect,
    ): bool {
        $sourceId = $this->taxonomyId($sourceDimension, $sourceSlug);
        $targetId = $this->taxonomyId($targetDimension, $targetSlug);

        return TaxonomyApplicabilityRule::query()
            ->where('canonical_key', TaxonomyApplicabilityRule::canonicalKey(
                TaxonomyDimension::from($sourceDimension),
                $sourceId,
                TaxonomyDimension::from($targetDimension),
                $targetId,
            ))
            ->where('effect', $effect)
            ->exists();
    }

    private function taxonomyId(string $dimension, string $slug): int
    {
        $id = match ($dimension) {
            'relationship' => Relationship::query()->where('slug', $slug)->value('id'),
            'occasion' => Occasion::query()->where('slug', $slug)->value('id'),
            'recipient_type' => RecipientType::query()->where('slug', $slug)->value('id'),
            'gift_type' => GiftType::query()->where('slug', $slug)->value('id'),
            default => null,
        };

        $this->assertNotNull($id, "Missing {$dimension} [{$slug}]");

        return (int) $id;
    }
}
