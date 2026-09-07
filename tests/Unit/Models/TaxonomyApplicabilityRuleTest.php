<?php

namespace Tests\Unit\Models;

use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyDimension;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Models\TaxonomyApplicabilityRule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaxonomyApplicabilityRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_the_same_source_and_target_row(): void
    {
        $husband = $this->relationship('Husband');

        $this->expectException(ValidationException::class);

        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => TaxonomyDimension::Relationship,
            'source_id' => $husband->id,
            'target_dimension' => TaxonomyDimension::Relationship,
            'target_id' => $husband->id,
            'effect' => TaxonomyApplicabilityEffect::Exclude,
            'reason' => 'invalid',
            'is_active' => true,
        ]);
    }

    public function test_it_rejects_a_reverse_duplicate_pair(): void
    {
        $husband = $this->relationship('Husband');
        $babyShower = $this->occasion('Baby Shower');

        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => TaxonomyDimension::Relationship,
            'source_id' => $husband->id,
            'target_dimension' => TaxonomyDimension::Occasion,
            'target_id' => $babyShower->id,
            'effect' => TaxonomyApplicabilityEffect::Exclude,
            'reason' => 'forward',
            'is_active' => true,
        ]);

        try {
            TaxonomyApplicabilityRule::query()->create([
                'source_dimension' => TaxonomyDimension::Occasion,
                'source_id' => $babyShower->id,
                'target_dimension' => TaxonomyDimension::Relationship,
                'target_id' => $husband->id,
                'effect' => TaxonomyApplicabilityEffect::Allow,
                'reason' => 'contradiction',
                'is_active' => true,
            ]);
            $this->fail('Expected a reverse pair to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_id', $exception->errors());
        }
    }

    public function test_canonical_key_unique_index_rejects_duplicate_rows(): void
    {
        $husband = $this->relationship('Husband');
        $babyShower = $this->occasion('Baby Shower');
        $key = TaxonomyApplicabilityRule::canonicalKey(
            TaxonomyDimension::Relationship,
            $husband->id,
            TaxonomyDimension::Occasion,
            $babyShower->id,
        );
        $now = now();

        $row = [
            'source_dimension' => TaxonomyDimension::Relationship->value,
            'source_id' => $husband->id,
            'target_dimension' => TaxonomyDimension::Occasion->value,
            'target_id' => $babyShower->id,
            'effect' => TaxonomyApplicabilityEffect::Exclude->value,
            'reason' => 'db',
            'is_active' => true,
            'canonical_key' => $key,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('taxonomy_applicability_rules')->insert($row);

        $this->expectException(UniqueConstraintViolationException::class);

        $row['source_dimension'] = TaxonomyDimension::Occasion->value;
        $row['source_id'] = $babyShower->id;
        $row['target_dimension'] = TaxonomyDimension::Relationship->value;
        $row['target_id'] = $husband->id;
        $row['effect'] = TaxonomyApplicabilityEffect::Allow->value;

        DB::table('taxonomy_applicability_rules')->insert($row);
    }

    public function test_it_rejects_a_missing_taxonomy_endpoint(): void
    {
        $husband = $this->relationship('Husband');

        $this->expectException(ValidationException::class);

        TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => TaxonomyDimension::Relationship,
            'source_id' => $husband->id,
            'target_dimension' => TaxonomyDimension::Occasion,
            'target_id' => 99_999,
            'effect' => TaxonomyApplicabilityEffect::Exclude,
            'reason' => 'missing',
            'is_active' => true,
        ]);
    }

    public function test_canonical_key_is_orientation_independent(): void
    {
        $husband = $this->relationship('Husband');
        $babyShower = $this->occasion('Baby Shower');

        $this->assertSame(
            TaxonomyApplicabilityRule::canonicalKey(
                TaxonomyDimension::Relationship,
                $husband->id,
                TaxonomyDimension::Occasion,
                $babyShower->id,
            ),
            TaxonomyApplicabilityRule::canonicalKey(
                TaxonomyDimension::Occasion,
                $babyShower->id,
                TaxonomyDimension::Relationship,
                $husband->id,
            ),
        );
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
