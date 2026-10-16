<?php

namespace Tests\Feature\RecipientEligibility;

use App\Actions\Recommendation\GenerateRecommendationsAction;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\RecipientType;
use App\Models\RecommendationResult;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsRecipientEligibilityCatalog;
use Tests\TestCase;

class RecipientEligibilityFinderTest extends TestCase
{
    use SeedsRecipientEligibilityCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedEligibilityTaxonomy();
    }

    public function test_flags_off_finder_is_strict_and_ignores_general_gifts_at_relationship_stages(): void
    {
        $this->enableRecipientEligibility(['enabled' => false, 'finder_audience' => true, 'surfaces.finder' => 'inferred']);
        $wallet = $this->wallet();

        $this->assertNotContains($wallet->id, $this->ranked());
    }

    public function test_relationship_stages_infer_general_gifts_but_group_formulas_are_unchanged(): void
    {
        $explicit = $this->eligibilityProduct('Husband Mug', ['relationships' => ['husband'], 'occasions' => ['birthday']]);
        $wallet = $this->wallet();

        $this->enableRecipientEligibility(['surfaces.finder' => 'inferred']);

        $results = collect($this->preview(['interests' => []]))->keyBy(fn (array $row): int => $row['product']->id);

        $this->assertTrue($results->has($wallet->id));
        $this->assertSame(RecommendationResult::GROUP_BEST, $results[$explicit->id]['group']);
        $this->assertSame(RecommendationResult::GROUP_RELAXED, $results[$wallet->id]['group'], 'inference never promotes a product into a group it does not earn from explicit tags');
        $this->assertSame(
            [$explicit->id, $wallet->id],
            array_keys($results->all()),
        );
    }

    public function test_interest_stage_is_never_expanded_by_inference_but_hard_contradictions_are_removed(): void
    {
        $general = $this->eligibilityProduct('Hex Dumbbell Pair', ['occasions' => ['birthday'], 'interests' => ['fitness'], 'genders' => ['unisex']]);
        $noGender = $this->eligibilityProduct('Resistance Bands', ['occasions' => ['birthday'], 'interests' => ['fitness']]);
        $womens = $this->eligibilityProduct('Women Yoga Mat', ['occasions' => ['birthday'], 'interests' => ['fitness'], 'genders' => ['female']]);
        $explicitWomens = $this->eligibilityProduct('Wife Fitness Band', ['relationships' => ['husband'], 'occasions' => ['birthday'], 'interests' => ['fitness'], 'genders' => ['female']]);

        $finder = app(GenerateRecommendationsAction::class);
        $input = $this->input(['interests' => ['fitness']]);

        $this->assertEqualsCanonicalizing(
            [$general->id, $noGender->id, $womens->id, $explicitWomens->id],
            $finder->interestStageProductIds($input),
        );

        $this->enableRecipientEligibility(['surfaces.finder' => 'inferred', 'finder_audience' => true, 'explicit_contradictions' => 'shadow']);

        $this->assertEqualsCanonicalizing(
            [$general->id, $noGender->id, $explicitWomens->id],
            $finder->interestStageProductIds($input),
            'a female-only product is a known contradiction for Husband; missing gender is not; explicit rows stay until enforcement',
        );

        $this->enableRecipientEligibility(['explicit_contradictions' => 'enforce']);

        $this->assertEqualsCanonicalizing(
            [$general->id, $noGender->id],
            $finder->interestStageProductIds($input),
        );
    }

    public function test_child_oriented_products_are_demoted_within_their_group_but_remain(): void
    {
        $kids = $this->eligibilityProduct('Kids Fitness Set', [
            'occasions' => ['birthday'], 'interests' => ['fitness'], 'genders' => ['unisex'], 'recipient_types' => ['kids'],
        ], ['is_featured' => true]);
        $adult = $this->eligibilityProduct('Adult Kettlebell', ['occasions' => ['birthday'], 'interests' => ['fitness'], 'genders' => ['unisex']]);
        $family = $this->eligibilityProduct('Family Fitness Game', [
            'occasions' => ['birthday'], 'interests' => ['fitness'], 'genders' => ['unisex'], 'recipient_types' => ['kids', 'college-student'],
        ]);

        $before = collect($this->preview(['interests' => ['fitness']]))->keyBy(fn (array $row): int => $row['product']->id);
        $this->assertSame($kids->id, $before->keys()->first(), 'featured kids product leads when demotion is off');

        $this->enableRecipientEligibility(['surfaces.finder' => 'inferred', 'finder_audience' => true]);

        $rows = collect($this->preview(['interests' => ['fitness']]))->keyBy(fn (array $row): int => $row['product']->id);
        $order = $rows->keys()->all();

        $this->assertSame($kids->id, end($order), 'exclusively child-oriented product is demoted to the end of its group');
        $this->assertLessThan(array_search($kids->id, $order, true), array_search($family->id, $order, true), 'family-friendly products are preserved');
        $this->assertArrayHasKey('audience_demoted', $rows[$kids->id]['breakdown']);
        $this->assertArrayNotHasKey('audience_demoted', $rows[$adult->id]['breakdown']);
        $this->assertArrayNotHasKey('audience_demoted', $rows[$family->id]['breakdown']);
        $this->assertSame($rows[$adult->id]['group'], $rows[$kids->id]['group'], 'the relevance group is unchanged');
        $this->assertSame($before[$kids->id]['score'], $rows[$kids->id]['score'], 'score formulas are unchanged');
        $this->assertSame($before[$kids->id]['group'], $rows[$kids->id]['group']);
    }

    public function test_demotion_never_outranks_a_better_relevance_group(): void
    {
        $this->enableRecipientEligibility(['surfaces.finder' => 'inferred', 'finder_audience' => true]);

        $interestAdult = $this->eligibilityProduct('Adult Kettlebell', ['occasions' => ['birthday'], 'interests' => ['fitness'], 'genders' => ['unisex']]);
        $bestKids = $this->eligibilityProduct('Kids Husband Gift', [
            'relationships' => ['husband'], 'occasions' => ['birthday'], 'interests' => ['fitness'], 'genders' => ['unisex'], 'recipient_types' => ['kids'],
        ]);

        $rows = collect($this->preview(['interests' => ['fitness']]))->keyBy(fn (array $row): int => $row['product']->id);

        $this->assertSame(RecommendationResult::GROUP_BEST, $rows[$bestKids->id]['group']);
        $this->assertSame($bestKids->id, $rows->keys()->first(), 'GROUP_BEST stays first even when demoted within it');
        $this->assertGreaterThan($rows[$bestKids->id]['group'], $rows[$interestAdult->id]['group']);
    }

    public function test_demotion_is_skipped_when_visitor_picked_a_child_recipient_type_or_a_child_relationship(): void
    {
        $this->enableRecipientEligibility(['surfaces.finder' => 'inferred', 'finder_audience' => true]);

        $this->eligibilityProduct('Kids Fitness Set', [
            'occasions' => ['birthday'], 'interests' => ['fitness'], 'genders' => ['unisex'], 'recipient_types' => ['kids'],
        ]);

        $withKidsType = $this->preview(['interests' => ['fitness'], 'recipient_type' => 'kids']);
        $this->assertArrayNotHasKey('audience_demoted', $withKidsType[0]['breakdown']);

        $son = $this->preview(['interests' => ['fitness'], 'relationship' => 'son']);
        $this->assertArrayNotHasKey('audience_demoted', $son[0]['breakdown'] ?? []);
    }

    public function test_hard_constraints_stay_in_force_for_inferred_candidates(): void
    {
        $noLink = $this->wallet('Wallet Without Link');
        $noLink->affiliateLinks()->delete();
        $male = $this->wallet('Wallet For Men', ['genders' => ['male']]);

        $this->enableRecipientEligibility(['surfaces.finder' => 'inferred', 'finder_audience' => true]);

        $ids = $this->ranked(['interests' => []]);

        $this->assertNotContains($noLink->id, $ids, 'a product without an active affiliate link is never recommended');
        $this->assertContains($male->id, $ids);
    }

    public function test_inference_creates_no_pivot_rows(): void
    {
        $this->wallet();
        $before = Product::query()->first()->relationships()->count();

        $this->enableRecipientEligibility(['surfaces.finder' => 'inferred', 'finder_audience' => true]);
        $this->preview(['interests' => []]);

        $this->assertSame($before, Product::query()->first()->relationships()->count());
        $this->assertSame(0, DB::table('relationship_product')->count());
    }

    private function wallet(string $name = 'Leather Wallet', array $tags = []): Product
    {
        return $this->eligibilityProduct($name, $tags + ['occasions' => ['anniversary'], 'genders' => ['unisex'], 'categories' => ['home-and-living']]);
    }

    /**
     * @param  array{interests?: list<string>, relationship?: string, recipient_type?: string}  $answers
     * @return list<int>
     */
    private function ranked(array $answers = []): array
    {
        return collect($this->preview($answers))->map(fn (array $row): int => $row['product']->id)->all();
    }

    /**
     * @param  array{interests?: list<string>, relationship?: string, recipient_type?: string}  $answers
     * @return list<array<string, mixed>>
     */
    private function preview(array $answers = []): array
    {
        return app(GenerateRecommendationsAction::class)->preview($this->input($answers));
    }

    /**
     * @param  array{interests?: list<string>, relationship?: string, recipient_type?: string}  $answers
     * @return array<string, mixed>
     */
    private function input(array $answers = []): array
    {
        return [
            'relationship_id' => $this->relationshipId($answers['relationship'] ?? 'husband'),
            'occasion_id' => Occasion::query()->where('slug', 'birthday')->value('id'),
            'recipient_type_id' => isset($answers['recipient_type'])
                ? RecipientType::query()->where('slug', $answers['recipient_type'])->value('id')
                : null,
            'interest_ids' => Interest::query()->whereIn('slug', $answers['interests'] ?? [])->pluck('id')->all(),
        ];
    }
}
