<?php

namespace Tests\Feature\Filament;

use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyDimension;
use App\Filament\Resources\TaxonomyApplicabilityRules\Pages\CreateTaxonomyApplicabilityRule;
use App\Filament\Resources\TaxonomyApplicabilityRules\Pages\EditTaxonomyApplicabilityRule;
use App\Filament\Resources\TaxonomyApplicabilityRules\Pages\ListTaxonomyApplicabilityRules;
use App\Models\Occasion;
use App\Models\Relationship;
use App\Models\TaxonomyApplicabilityRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaxonomyApplicabilityRuleResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_page_loads(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ListTaxonomyApplicabilityRules::class)
            ->assertOk();
    }

    public function test_it_can_create_an_exclude_rule(): void
    {
        $this->actingAs(User::factory()->create());

        $husband = $this->relationship('Husband');
        $babyShower = $this->occasion('Baby Shower');

        Livewire::test(CreateTaxonomyApplicabilityRule::class)
            ->fillForm([
                'source_dimension' => TaxonomyDimension::Relationship->value,
                'source_id' => $husband->id,
                'target_dimension' => TaxonomyDimension::Occasion->value,
                'target_id' => $babyShower->id,
                'effect' => TaxonomyApplicabilityEffect::Exclude->value,
                'reason' => 'Baby showers are not a husband gifting occasion.',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('taxonomy_applicability_rules', [
            'source_dimension' => TaxonomyDimension::Relationship->value,
            'source_id' => $husband->id,
            'target_dimension' => TaxonomyDimension::Occasion->value,
            'target_id' => $babyShower->id,
            'effect' => TaxonomyApplicabilityEffect::Exclude->value,
            'is_active' => true,
        ]);
    }

    public function test_it_rejects_the_same_source_and_target_row(): void
    {
        $this->actingAs(User::factory()->create());

        $husband = $this->relationship('Husband');

        Livewire::test(CreateTaxonomyApplicabilityRule::class)
            ->fillForm([
                'source_dimension' => TaxonomyDimension::Relationship->value,
                'source_id' => $husband->id,
                'target_dimension' => TaxonomyDimension::Relationship->value,
                'target_id' => $husband->id,
                'effect' => TaxonomyApplicabilityEffect::Exclude->value,
                'reason' => 'invalid',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['target_id']);
    }

    public function test_it_rejects_a_reverse_or_contradictory_duplicate_pair(): void
    {
        $this->actingAs(User::factory()->create());

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

        Livewire::test(CreateTaxonomyApplicabilityRule::class)
            ->fillForm([
                'source_dimension' => TaxonomyDimension::Occasion->value,
                'source_id' => $babyShower->id,
                'target_dimension' => TaxonomyDimension::Relationship->value,
                'target_id' => $husband->id,
                'effect' => TaxonomyApplicabilityEffect::Allow->value,
                'reason' => 'contradiction',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['target_id']);
    }

    public function test_it_can_edit_reason_and_active_flag(): void
    {
        $this->actingAs(User::factory()->create());

        $husband = $this->relationship('Husband');
        $babyShower = $this->occasion('Baby Shower');

        $rule = TaxonomyApplicabilityRule::query()->create([
            'source_dimension' => TaxonomyDimension::Relationship,
            'source_id' => $husband->id,
            'target_dimension' => TaxonomyDimension::Occasion,
            'target_id' => $babyShower->id,
            'effect' => TaxonomyApplicabilityEffect::Exclude,
            'reason' => 'old reason',
            'is_active' => true,
        ]);

        Livewire::test(EditTaxonomyApplicabilityRule::class, [
            'record' => $rule->getRouteKey(),
        ])
            ->fillForm([
                'reason' => 'updated reason',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('taxonomy_applicability_rules', [
            'id' => $rule->id,
            'reason' => 'updated reason',
            'is_active' => false,
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
}
