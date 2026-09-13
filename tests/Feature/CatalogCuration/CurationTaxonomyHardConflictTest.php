<?php

namespace Tests\Feature\CatalogCuration;

use App\Actions\CatalogCuration\BuildCurationTaxonomyDifferencesAction;
use App\CatalogCuration\ProductCurationEvidence;
use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyDimension;
use App\Models\Occasion;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\TaxonomyApplicabilityRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurationTaxonomyHardConflictTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_valentines_day_relationship_restriction_remains_blocking(): void
    {
        $valentines = $this->occasion("Valentine's Day");
        $husband = $this->relationship('Husband');
        $brother = $this->relationship('Brother');
        $this->rule(
            $valentines,
            TaxonomyDimension::Occasion,
            $husband,
            TaxonomyDimension::Relationship,
            TaxonomyApplicabilityEffect::Allow,
        );

        $this->assertBlockingConflict($this->evidence(
            relationships: [$brother],
            occasions: [$valentines],
        ));
    }

    public function test_configured_farewell_and_kids_exclusion_remains_blocking(): void
    {
        $farewell = $this->occasion('Farewell');
        $kids = $this->recipientType('Kids');
        $this->rule(
            $kids,
            TaxonomyDimension::RecipientType,
            $farewell,
            TaxonomyDimension::Occasion,
            TaxonomyApplicabilityEffect::Exclude,
        );

        $this->assertBlockingConflict($this->evidence(
            occasions: [$farewell],
            recipientTypes: [$kids],
        ));
    }

    public function test_configured_baby_shower_and_husband_exclusion_remains_blocking(): void
    {
        $babyShower = $this->occasion('Baby Shower');
        $husband = $this->relationship('Husband');
        $this->rule(
            $husband,
            TaxonomyDimension::Relationship,
            $babyShower,
            TaxonomyDimension::Occasion,
            TaxonomyApplicabilityEffect::Exclude,
        );

        $this->assertBlockingConflict($this->evidence(
            relationships: [$husband],
            occasions: [$babyShower],
        ));
    }

    private function assertBlockingConflict(ProductCurationEvidence $evidence): void
    {
        $before = TaxonomyApplicabilityRule::query()->count();
        $differences = app(BuildCurationTaxonomyDifferencesAction::class)->execute(
            $evidence,
            $this->emptySemantic(),
        );
        $conflict = collect($differences)->firstWhere('cause', 'hard_applicability_conflict');

        $this->assertIsArray($conflict);
        $this->assertSame('blocking', $conflict['severity']);
        $this->assertTrue($conflict['forces_human_review']);
        $this->assertTrue($conflict['hard_rule_violation']);
        $this->assertSame($before, TaxonomyApplicabilityRule::query()->count());
    }

    /**
     * @param  list<Relationship>  $relationships
     * @param  list<Occasion>  $occasions
     * @param  list<RecipientType>  $recipientTypes
     */
    private function evidence(
        array $relationships = [],
        array $occasions = [],
        array $recipientTypes = [],
    ): ProductCurationEvidence {
        return new ProductCurationEvidence(
            productId: 10,
            name: 'Conflict fixture',
            shortDescription: null,
            description: null,
            brand: null,
            status: 'draft',
            priceAmount: '1000.00',
            priceCurrency: 'INR',
            compareAtAmount: null,
            rating: null,
            reviewCount: null,
            taxonomy: [
                'categories' => [],
                'relationships' => $this->rows($relationships),
                'occasions' => $this->rows($occasions),
                'interests' => [],
                'gift_types' => [],
                'recipient_types' => $this->rows($recipientTypes),
                'professions' => [],
            ],
            offers: [],
            images: [],
            provenance: [],
        );
    }

    /**
     * @param  list<Model>  $models
     * @return list<array{id: int, name: string, slug: string}>
     */
    private function rows(array $models): array
    {
        return array_map(fn (Model $model): array => [
            'id' => (int) $model->getKey(),
            'name' => (string) $model->getAttribute('name'),
            'slug' => (string) $model->getAttribute('slug'),
        ], $models);
    }

    /**
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function emptySemantic(): array
    {
        return [
            'current_taxonomy_evaluations' => [
                'relationships' => [],
                'occasions' => [],
                'interests' => [],
                'gift_types' => [],
            ],
            'taxonomy_suggestions' => [
                'relationships' => [],
                'occasions' => [],
                'interests' => [],
                'gift_types' => [],
            ],
        ];
    }

    private function rule(
        Model $source,
        TaxonomyDimension $sourceDimension,
        Model $target,
        TaxonomyDimension $targetDimension,
        TaxonomyApplicabilityEffect $effect,
    ): void {
        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => $sourceDimension,
            'source_id' => $source->getKey(),
            'target_dimension' => $targetDimension,
            'target_id' => $target->getKey(),
            'effect' => $effect,
            'reason' => 'Authoritative configured regression fixture.',
            'is_active' => true,
        ]);
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

    private function recipientType(string $name): RecipientType
    {
        return RecipientType::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
        ]);
    }
}
