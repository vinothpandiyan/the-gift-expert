<?php

namespace Tests\Unit\Actions\RecipientMerchandising;

use App\Actions\RecipientMerchandising\BackfillProductRecipientMerchandisingAction;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\Product;
use Database\Seeders\RecipientGenderSeeder;
use Database\Seeders\RecipientTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillProductRecipientMerchandisingActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RecipientGenderSeeder::class,
            RecipientTypeSeeder::class,
        ]);
    }

    public function test_dry_run_classifies_high_confidence_male_female_and_unisex(): void
    {
        $male = Product::factory()->create(['name' => "Men's Grooming Gift Set"]);
        $female = Product::factory()->create(['name' => "Women's Leather Handbag"]);
        $unisex = Product::factory()->create(['name' => 'Catan Strategy Board Game']);

        $result = app(BackfillProductRecipientMerchandisingAction::class)->execute(
            dryRun: true,
            productIds: [$male->id, $female->id, $unisex->id],
        );

        $this->assertTrue($result->dryRun);
        $this->assertSame(3, $result->wouldApply);
        $this->assertSame(1, $result->genderCounts['male']);
        $this->assertSame(1, $result->genderCounts['female']);
        $this->assertSame(1, $result->genderCounts['unisex']);
        $this->assertSame(0, $male->fresh()->recipientGenders()->count());
        $this->assertCount(1, $result->sampleGroups['male']);
        $this->assertCount(1, $result->sampleGroups['female']);
        $this->assertCount(1, $result->sampleGroups['unisex']);
    }

    public function test_inventory_reports_published_draft_and_archived_separately(): void
    {
        $published = Product::factory()->create([
            'name' => "Men's Watch",
            'status' => ProductStatus::Published,
            'published_at' => now(),
        ]);
        $draft = Product::factory()->create([
            'name' => "Women's Handbag",
            'status' => ProductStatus::Draft,
        ]);
        $archived = Product::factory()->create([
            'name' => 'Catan Board Game',
            'status' => ProductStatus::Archived,
        ]);
        $deleted = Product::factory()->create(['name' => "Men's Belt"]);
        $deleted->delete();

        $result = app(BackfillProductRecipientMerchandisingAction::class)->execute(dryRun: true);

        $this->assertSame(4, $result->inventory['total_rows']);
        $this->assertSame(1, $result->inventory['excluded_soft_deleted']);
        $this->assertSame(3, $result->inventory['eligible']);
        $this->assertSame(1, $result->inventory['eligible_published']);
        $this->assertSame(1, $result->inventory['eligible_draft']);
        $this->assertSame(1, $result->inventory['eligible_archived']);
        $this->assertSame(3, $result->examined);
        $this->assertContains($published->id, collect($result->assignments)->pluck('productId'));
        $this->assertContains($draft->id, collect($result->assignments)->pluck('productId'));
        $this->assertContains($archived->id, collect($result->assignments)->pluck('productId'));
    }

    public function test_commit_applies_gender_without_touching_human_locked_products(): void
    {
        $locked = Product::factory()->create([
            'name' => "Men's Wallet",
            'taxonomy_classification_status' => TaxonomyClassificationStatus::HumanApproved,
        ]);
        $open = Product::factory()->create(['name' => "Men's Watch"]);

        $result = app(BackfillProductRecipientMerchandisingAction::class)->execute(
            dryRun: false,
            productIds: [$locked->id, $open->id],
        );

        $this->assertSame(1, $result->skippedHumanLocked);
        $this->assertSame(1, $result->applied);
        $this->assertSame(0, $locked->fresh()->recipientGenders()->count());
        $this->assertSame(['male'], $open->fresh()->recipientGenders()->pluck('slug')->all());
    }

    public function test_detects_baby_and_college_student_life_stages(): void
    {
        $baby = Product::factory()->create(['name' => 'Baby Sensory Soft Toy']);
        $college = Product::factory()->create(['name' => 'Laptop Backpack for College']);

        $result = app(BackfillProductRecipientMerchandisingAction::class)->execute(
            dryRun: false,
            productIds: [$baby->id, $college->id],
        );

        $this->assertGreaterThanOrEqual(1, $result->recipientTypeCounts['baby']);
        $this->assertGreaterThanOrEqual(1, $result->recipientTypeCounts['college-student']);
        $this->assertTrue($baby->fresh()->recipientTypes()->where('slug', 'baby')->exists());
        $this->assertTrue($college->fresh()->recipientTypes()->where('slug', 'college-student')->exists());
        $this->assertNotEmpty($result->sampleGroups['baby']);
        $this->assertNotEmpty($result->sampleGroups['college-student']);
    }

    public function test_ambiguous_products_are_reported_for_review(): void
    {
        $ambiguous = Product::factory()->create(['name' => 'Luxury Fragrance Gift Set']);

        $result = app(BackfillProductRecipientMerchandisingAction::class)->execute(
            dryRun: true,
            productIds: [$ambiguous->id],
        );

        $this->assertSame(1, $result->ambiguous);
        $this->assertSame(0, $result->wouldApply);
        $this->assertCount(1, $result->sampleGroups['ambiguous']);
    }
}
