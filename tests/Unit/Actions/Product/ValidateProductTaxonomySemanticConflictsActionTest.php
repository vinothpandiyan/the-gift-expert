<?php

namespace Tests\Unit\Actions\Product;

use App\Actions\Product\ValidateProductTaxonomySemanticConflictsAction;
use App\CommercialSourcing\ValidatedProductTaxonomyClassification;
use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyDimension;
use App\Models\Occasion;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\TaxonomyApplicabilityRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidateProductTaxonomySemanticConflictsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_husband_and_raksha_bandhan_conflict(): void
    {
        $husband = $this->relationship('Husband');
        $brother = $this->relationship('Brother');
        $sister = $this->relationship('Sister');
        $raksha = $this->occasion('Raksha Bandhan');

        $this->allow($raksha, TaxonomyDimension::Occasion, $brother, TaxonomyDimension::Relationship);
        $this->allow($raksha, TaxonomyDimension::Occasion, $sister, TaxonomyDimension::Relationship);

        $conflicts = app(ValidateProductTaxonomySemanticConflictsAction::class)->execute(
            $this->taxonomy(relationshipIds: [$husband->id], occasionIds: [$raksha->id]),
        );

        $this->assertCount(1, $conflicts);
    }

    public function test_brother_and_raksha_bandhan_are_valid(): void
    {
        $brother = $this->relationship('Brother');
        $sister = $this->relationship('Sister');
        $raksha = $this->occasion('Raksha Bandhan');

        $this->allow($raksha, TaxonomyDimension::Occasion, $brother, TaxonomyDimension::Relationship);
        $this->allow($raksha, TaxonomyDimension::Occasion, $sister, TaxonomyDimension::Relationship);

        $conflicts = app(ValidateProductTaxonomySemanticConflictsAction::class)->execute(
            $this->taxonomy(relationshipIds: [$brother->id], occasionIds: [$raksha->id]),
        );

        $this->assertSame([], $conflicts);
    }

    public function test_kids_and_retirement_conflict(): void
    {
        $kids = $this->recipientType('Kids');
        $retirement = $this->occasion('Retirement');

        $this->exclude($kids, TaxonomyDimension::RecipientType, $retirement, TaxonomyDimension::Occasion);

        $conflicts = app(ValidateProductTaxonomySemanticConflictsAction::class)->execute(
            $this->taxonomy(recipientTypeIds: [$kids->id], occasionIds: [$retirement->id]),
        );

        $this->assertCount(1, $conflicts);
    }

    public function test_wife_and_baby_shower_are_valid_without_an_exclude_rule(): void
    {
        $wife = $this->relationship('Wife');
        $husband = $this->relationship('Husband');
        $babyShower = $this->occasion('Baby Shower');

        $this->exclude($husband, TaxonomyDimension::Relationship, $babyShower, TaxonomyDimension::Occasion);

        $conflicts = app(ValidateProductTaxonomySemanticConflictsAction::class)->execute(
            $this->taxonomy(relationshipIds: [$wife->id], occasionIds: [$babyShower->id]),
        );

        $this->assertSame([], $conflicts);
    }

    /**
     * @param  list<int>  $relationshipIds
     * @param  list<int>  $occasionIds
     * @param  list<int>  $recipientTypeIds
     */
    private function taxonomy(
        array $relationshipIds = [],
        array $occasionIds = [],
        array $recipientTypeIds = [],
    ): ValidatedProductTaxonomyClassification {
        return new ValidatedProductTaxonomyClassification(
            primaryCategoryId: 1,
            categoryIds: [1],
            occasionIds: $occasionIds,
            relationshipIds: $relationshipIds,
            recipientTypeIds: $recipientTypeIds,
            interestIds: [],
            professionIds: [],
            giftTypeIds: [],
            exceptionCodes: [],
            rejectedIds: [],
        );
    }

    private function exclude(
        object $source,
        TaxonomyDimension $sourceDimension,
        object $target,
        TaxonomyDimension $targetDimension,
    ): void {
        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => $sourceDimension,
            'source_id' => $source->id,
            'target_dimension' => $targetDimension,
            'target_id' => $target->id,
            'effect' => TaxonomyApplicabilityEffect::Exclude,
            'reason' => 'test',
            'is_active' => true,
        ]);
    }

    private function allow(
        object $source,
        TaxonomyDimension $sourceDimension,
        object $target,
        TaxonomyDimension $targetDimension,
    ): void {
        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => $sourceDimension,
            'source_id' => $source->id,
            'target_dimension' => $targetDimension,
            'target_id' => $target->id,
            'effect' => TaxonomyApplicabilityEffect::Allow,
            'reason' => 'test',
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
