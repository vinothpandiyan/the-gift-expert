<?php

namespace Tests\Unit\Actions\RecipientMerchandising;

use App\Actions\CatalogCandidate\ValidateProductTaxonomyClassificationAction;
use App\Actions\Product\QueryPublishedProductsByFiltersAction;
use App\Actions\RecipientMerchandising\ResolveRecipientMerchandisingCompositionAction;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\Product;
use App\Models\RecipientGender;
use App\Models\RecipientType;
use App\Models\Relationship;
use Database\Seeders\RecipientGenderSeeder;
use Database\Seeders\RecipientTypeSeeder;
use Database\Seeders\RelationshipSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipientMerchandisingCompositionAndFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RelationshipSeeder::class,
            RecipientTypeSeeder::class,
            RecipientGenderSeeder::class,
        ]);
    }

    public function test_female_friend_composes_friends_and_female(): void
    {
        $filters = $this->compose('female friend');

        $this->assertSame($this->relationshipId('friends'), $filters['relationship_id']);
        $this->assertSame($this->genderId('female'), $filters['recipient_gender_id']);
        $this->assertArrayNotHasKey('recipient_type_id', $filters);
    }

    public function test_male_friend_composes_friends_and_male(): void
    {
        $filters = $this->compose('male friend');

        $this->assertSame($this->relationshipId('friends'), $filters['relationship_id']);
        $this->assertSame($this->genderId('male'), $filters['recipient_gender_id']);
    }

    public function test_school_friend_boy_composes_friends_school_student_and_male(): void
    {
        $filters = $this->compose('school friend boy');

        $this->assertSame($this->relationshipId('friends'), $filters['relationship_id']);
        $this->assertSame($this->recipientTypeId('school-student'), $filters['recipient_type_id']);
        $this->assertSame($this->genderId('male'), $filters['recipient_gender_id']);
    }

    public function test_school_friend_girl_composes_friends_school_student_and_female(): void
    {
        $filters = $this->compose('school friend girl');

        $this->assertSame($this->relationshipId('friends'), $filters['relationship_id']);
        $this->assertSame($this->recipientTypeId('school-student'), $filters['recipient_type_id']);
        $this->assertSame($this->genderId('female'), $filters['recipient_gender_id']);
    }

    public function test_college_boy_and_girl_compose_college_student_with_gender(): void
    {
        $boy = $this->compose('college boy');
        $girl = $this->compose('college girl');

        $this->assertSame($this->recipientTypeId('college-student'), $boy['recipient_type_id']);
        $this->assertSame($this->genderId('male'), $boy['recipient_gender_id']);
        $this->assertArrayNotHasKey('relationship_id', $boy);

        $this->assertSame($this->recipientTypeId('college-student'), $girl['recipient_type_id']);
        $this->assertSame($this->genderId('female'), $girl['recipient_gender_id']);
    }

    public function test_male_and_female_colleague_compose_colleagues_with_gender(): void
    {
        $male = $this->compose('male colleague');
        $female = $this->compose('female colleague');

        $this->assertSame($this->relationshipId('colleagues'), $male['relationship_id']);
        $this->assertSame($this->genderId('male'), $male['recipient_gender_id']);
        $this->assertSame($this->relationshipId('colleagues'), $female['relationship_id']);
        $this->assertSame($this->genderId('female'), $female['recipient_gender_id']);
    }

    public function test_baby_boy_and_girl_compose_baby_with_gender(): void
    {
        $boy = $this->compose('baby boy');
        $girl = $this->compose('baby girl');

        $this->assertSame($this->recipientTypeId('baby'), $boy['recipient_type_id']);
        $this->assertSame($this->genderId('male'), $boy['recipient_gender_id']);
        $this->assertSame($this->recipientTypeId('baby'), $girl['recipient_type_id']);
        $this->assertSame($this->genderId('female'), $girl['recipient_gender_id']);
    }

    public function test_kids_without_gender_composes_kids_only(): void
    {
        $filters = $this->compose('kids');

        $this->assertSame($this->recipientTypeId('kids'), $filters['recipient_type_id']);
        $this->assertArrayNotHasKey('recipient_gender_id', $filters);
    }

    public function test_boy_kids_and_girl_kids_compose_kids_with_gender(): void
    {
        $boys = $this->compose('boy kids');
        $girls = $this->compose('girl kids');

        $this->assertSame($this->recipientTypeId('kids'), $boys['recipient_type_id']);
        $this->assertSame($this->genderId('male'), $boys['recipient_gender_id']);
        $this->assertSame($this->recipientTypeId('kids'), $girls['recipient_type_id']);
        $this->assertSame($this->genderId('female'), $girls['recipient_gender_id']);
    }

    public function test_men_and_women_compose_gender_only(): void
    {
        $this->assertSame(
            ['recipient_gender_id' => $this->genderId('male')],
            $this->compose('men'),
        );
        $this->assertSame(
            ['recipient_gender_id' => $this->genderId('female')],
            $this->compose('women'),
        );
    }

    public function test_male_filter_includes_male_and_unisex_excludes_female(): void
    {
        $friends = $this->relationshipId('friends');
        $male = $this->publishedWithGender('male-wallet', 'male');
        $female = $this->publishedWithGender('female-bag', 'female');
        $unisex = $this->publishedWithGender('echo-dot', 'unisex');
        $male->relationships()->attach($friends);
        $female->relationships()->attach($friends);
        $unisex->relationships()->attach($friends);

        $ids = $this->filteredIds([
            'relationship_id' => $friends,
            'recipient_gender_id' => $this->genderId('male'),
        ]);

        $this->assertEqualsCanonicalizing([$male->id, $unisex->id], $ids);
        $this->assertNotContains($female->id, $ids);
    }

    public function test_female_filter_includes_female_and_unisex_excludes_male(): void
    {
        $friends = $this->relationshipId('friends');
        $male = $this->publishedWithGender('male-grooming', 'male');
        $female = $this->publishedWithGender('female-jewellery', 'female');
        $unisex = $this->publishedWithGender('board-game', 'unisex');
        $male->relationships()->attach($friends);
        $female->relationships()->attach($friends);
        $unisex->relationships()->attach($friends);

        $ids = $this->filteredIds([
            'relationship_id' => $friends,
            'recipient_gender_id' => $this->genderId('female'),
        ]);

        $this->assertEqualsCanonicalizing([$female->id, $unisex->id], $ids);
        $this->assertNotContains($male->id, $ids);
    }

    public function test_no_gender_filter_does_not_exclude_any_gendered_products(): void
    {
        $friends = $this->relationshipId('friends');
        $male = $this->publishedWithGender('male-a', 'male');
        $female = $this->publishedWithGender('female-a', 'female');
        $unisex = $this->publishedWithGender('unisex-a', 'unisex');
        $male->relationships()->attach($friends);
        $female->relationships()->attach($friends);
        $unisex->relationships()->attach($friends);

        $ids = $this->filteredIds(['relationship_id' => $friends]);

        $this->assertEqualsCanonicalizing([$male->id, $female->id, $unisex->id], $ids);
    }

    public function test_composed_school_friend_boy_filters_products(): void
    {
        $filters = $this->compose('school friend boy');

        $match = $this->publishedGift('school-boy-gift');
        $match->relationships()->attach($filters['relationship_id']);
        $match->recipientTypes()->attach($filters['recipient_type_id']);
        $match->recipientGenders()->attach($filters['recipient_gender_id']);

        $wrongGender = $this->publishedGift('school-girl-gift');
        $wrongGender->relationships()->attach($filters['relationship_id']);
        $wrongGender->recipientTypes()->attach($filters['recipient_type_id']);
        $wrongGender->recipientGenders()->attach($this->genderId('female'));

        $ids = $this->filteredIds($filters);

        $this->assertSame([$match->id], $ids);
    }

    public function test_validate_rejects_unknown_recipient_gender_ids(): void
    {
        $validated = app(ValidateProductTaxonomyClassificationAction::class)
            ->execute([
                'primary_category_id' => null,
                'category_ids' => [],
                'occasion_ids' => [],
                'relationship_ids' => [],
                'recipient_type_ids' => [],
                'recipient_gender_ids' => [999999],
                'interest_ids' => [],
                'profession_ids' => [],
                'gift_type_ids' => [],
            ]);

        $this->assertSame([], $validated->recipientGenderIds);
        $this->assertContains(999999, $validated->rejectedIds);
    }

    public function test_human_locked_products_are_not_overwritten_by_apply_action_without_allow(): void
    {
        $product = Product::factory()->create([
            'status' => ProductStatus::Draft,
            'taxonomy_classification_status' => TaxonomyClassificationStatus::HumanOverridden,
        ]);
        $product->recipientGenders()->sync([$this->genderId('unisex')]);

        $this->assertTrue($product->taxonomy_classification_status->isHumanLocked());
        $this->assertSame(
            ['unisex'],
            $product->fresh()->recipientGenders()->pluck('slug')->all(),
        );
    }

    /**
     * @return array<string, int>
     */
    private function compose(string $intent): array
    {
        return app(ResolveRecipientMerchandisingCompositionAction::class)->execute($intent);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private function filteredIds(array $filters): array
    {
        return app(QueryPublishedProductsByFiltersAction::class)
            ->execute($filters)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function publishedWithGender(string $slug, string $genderSlug): Product
    {
        $product = $this->publishedGift($slug);
        $product->recipientGenders()->attach($this->genderId($genderSlug));

        return $product;
    }

    private function publishedGift(string $slug): Product
    {
        return Product::factory()->published()->create([
            'slug' => $slug,
            'price_amount' => '500.00',
            'price_currency' => 'INR',
        ]);
    }

    private function relationshipId(string $slug): int
    {
        return (int) Relationship::query()->where('slug', $slug)->value('id');
    }

    private function recipientTypeId(string $slug): int
    {
        return (int) RecipientType::query()->where('slug', $slug)->value('id');
    }

    private function genderId(string $slug): int
    {
        return (int) RecipientGender::query()->where('slug', $slug)->value('id');
    }
}
