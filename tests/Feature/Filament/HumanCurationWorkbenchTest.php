<?php

namespace Tests\Feature\Filament;

use App\Actions\CatalogCuration\BuildCurationReviewReasonsAction;
use App\Actions\CatalogCuration\BuildHumanCurationReviewAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\P3QualitySubgroup;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationDecisionReasonCode;
use App\Enums\ProductCurationRemediationStatus;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Filament\Resources\HumanCuration\HumanCurationResource;
use App\Filament\Resources\HumanCuration\Pages\ListHumanCuration;
use App\Filament\Resources\HumanCuration\Pages\ReviewHumanCuration;
use App\Models\AffiliateLink;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\BuildsHumanCurationFixtures;
use Tests\TestCase;

class HumanCurationWorkbenchTest extends TestCase
{
    use BuildsHumanCurationFixtures;
    use RefreshDatabase;

    public function test_guests_cannot_open_the_workbench(): void
    {
        $this->get(HumanCurationResource::getUrl('index'))->assertRedirect();
    }

    public function test_queue_renders_filters_and_default_needs_review_view(): void
    {
        $this->actingAs(User::factory()->create());
        $run = $this->acceptedCurationRun();
        $review = $this->curatedAudit($run, Product::factory()->create(['name' => 'Needs Human Review']));
        $advisory = $this->curatedAudit($run, Product::factory()->create(['name' => 'Advisory Only']), [
            'requires_human_review' => false,
            'gift_score' => 84,
            'catalog_value_score' => 72,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(ListHumanCuration::class)
            ->assertOk()
            ->assertSee('Human Curation Workbench')
            ->assertSee('Needs Review')
            ->assertSee('Needs Human Review')
            ->assertDontSee('Advisory Only')
            ->assertSee('Mandatory review: 1');

        $this->assertLessThan(40, count(DB::getQueryLog()));

        Livewire::test(ListHumanCuration::class)
            ->set('activeTab', 'p4')
            ->assertSee('Advisory Only')
            ->assertCanSeeTableRecords([$advisory->product])
            ->assertCanNotSeeTableRecords([$review->product]);
    }

    public function test_review_page_shows_scores_reasons_peers_and_saves_a_decision(): void
    {
        $user = User::factory()->create(['name' => 'Catalog Editor']);
        $this->actingAs($user);
        $run = $this->acceptedCurationRun();
        $current = $this->curatedAudit($run, Product::factory()->create(['name' => 'Current Wallet']), [
            'concept_key' => 'wallet',
            'concept_label' => 'Wallet',
            'gift_score' => 62,
            'catalog_value_score' => 12,
            'differentiation_factor' => 6,
            'peer_counts' => ['concept' => 1],
            'peer_product_ids' => ['concept' => []],
            'catalog_context_snapshot' => [
                'differentiation_strength' => 'weak',
                'relative_coverage' => [
                    'concept_peer_count' => 1,
                    'concept_exact_component' => 10,
                    'context_scarcity' => 0.2,
                    'shared_scarcity_scale' => 1,
                ],
            ],
            'issues' => [[
                'code' => 'low_catalog_value',
                'severity' => 'warning',
                'message' => 'Catalog value scored below the human-review baseline.',
                'context' => [],
            ]],
        ]);
        $peer = $this->curatedAudit($run, Product::factory()->create(['name' => 'Peer Wallet']), [
            'concept_key' => 'wallet',
            'concept_label' => 'Wallet',
            'gift_score' => 68,
            'catalog_value_score' => 28,
            'peer_counts' => ['concept' => 1],
        ]);
        $current->update([
            'peer_product_ids' => ['concept' => [$peer->product_id]],
        ]);

        $reasons = app(BuildCurationReviewReasonsAction::class)->execute($current->fresh());
        $this->assertNotEmpty($reasons);

        $case = app(BuildHumanCurationReviewAction::class)->execute($current->product->fresh());
        $this->assertNotNull($case);
        $this->assertSame('Wallet', $case->conceptLabel);
        $this->assertCount(1, $case->peers);

        Livewire::test(ReviewHumanCuration::class, ['record' => $current->product->getRouteKey()])
            ->assertOk()
            ->assertSee('Why this needs review')
            ->assertSee('Catalog Value is low: 12')
            ->assertSee('Gift Score breakdown')
            ->assertSee('Catalog Value breakdown')
            ->assertSee('Relative coverage')
            ->assertSee('Current Wallet')
            ->assertSee('Peer Wallet')
            ->assertSee('Cluster curation question')
            ->assertSee('Decision history')
            ->fillForm([
                'decision' => ProductCurationDecision::Keep->value,
                'reason_codes' => [ProductCurationDecisionReasonCode::GoodValueForMoney->value],
                'reason_notes' => 'Best value in the cluster.',
            ])
            ->call('saveDecision')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('product_curation_decisions', [
            'product_id' => $current->product_id,
            'decision' => ProductCurationDecision::Keep->value,
            'source_audit_run_id' => $run->id,
            'source_product_curation_audit_id' => $current->id,
            'decided_by_user_id' => $user->id,
        ]);

        Livewire::test(ReviewHumanCuration::class, ['record' => $current->product->getRouteKey()])
            ->assertSee('Keep')
            ->assertSee('Catalog Editor')
            ->assertSee($run->id);
    }

    public function test_decision_form_validates_defer_and_save_and_next_advances(): void
    {
        $this->actingAs(User::factory()->create());
        $run = $this->acceptedCurationRun();
        $first = $this->curatedAudit($run, Product::factory()->create(['name' => 'First Review Gift']));
        $second = $this->curatedAudit($run, Product::factory()->create(['name' => 'Second Review Gift']));

        Livewire::test(ReviewHumanCuration::class, ['record' => $first->product->getRouteKey()])
            ->fillForm([
                'decision' => ProductCurationDecision::Defer->value,
                'reason_codes' => [],
                'reason_notes' => null,
            ])
            ->call('saveDecision')
            ->assertHasFormErrors(['reason_notes']);

        $component = Livewire::test(ReviewHumanCuration::class, [
            'record' => $first->product->getRouteKey(),
            'queueTab' => 'needs_review',
        ])
            ->fillForm([
                'decision' => ProductCurationDecision::Keep->value,
                'reason_codes' => [ProductCurationDecisionReasonCode::StrongGift->value],
            ])
            ->call('saveDecision', 'next');

        $component->assertRedirect(HumanCurationResource::getUrl('review', [
            'record' => $second->product,
            'queueTab' => 'needs_review',
        ]));
    }

    public function test_review_page_shows_p0_diagnosis_without_recording_a_decision(): void
    {
        $this->actingAs(User::factory()->create());
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run, Product::factory()->create(['name' => 'Test Product Placeholder']), [
            'gift_score' => 0,
            'catalog_value_score' => 0,
            'issues' => [[
                'code' => 'missing_commerce_evidence',
                'severity' => 'warning',
                'message' => 'Commerce evidence is missing.',
                'context' => [],
            ]],
        ]);

        Livewire::test(ReviewHumanCuration::class, ['record' => $audit->product->getRouteKey()])
            ->assertOk()
            ->assertSee('P0 integrity diagnosis')
            ->assertSee('Catalog data defect')
            ->assertDontSee('Human curation decision recorded');

        $this->assertDatabaseMissing('product_curation_decisions', [
            'product_id' => $audit->product_id,
        ]);
    }

    public function test_saving_deactivate_archives_the_product(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run, Product::factory()->create([
            'name' => 'Disposable Test Gift',
            'status' => ProductStatus::Published,
        ]));

        Livewire::test(ReviewHumanCuration::class, ['record' => $audit->product->getRouteKey()])
            ->fillForm([
                'decision' => ProductCurationDecision::Deactivate->value,
                'reason_codes' => [ProductCurationDecisionReasonCode::WeakGiftFit->value],
                'reason_notes' => 'Development placeholder that should not remain active inventory.',
            ])
            ->call('saveDecision')
            ->assertHasNoFormErrors();

        $this->assertSame(ProductStatus::Archived, $audit->product->fresh()->status);
        $this->assertDatabaseHas('product_curation_decisions', [
            'product_id' => $audit->product_id,
            'decision' => ProductCurationDecision::Deactivate->value,
            'remediation_status' => 'completed',
        ]);
    }

    public function test_p2_review_records_reclassify_preview_and_executes_remediation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $run = $this->acceptedCurationRun();
        $product = Product::factory()->create([
            'name' => 'Misleading Graduation Gift',
            'status' => ProductStatus::Published,
            'taxonomy_classification_status' => TaxonomyClassificationStatus::AiAccepted,
        ]);
        $attached = $this->attachMerchandisingTaxonomy($product);
        $audit = $this->curatedAudit($run, $product, [
            'taxonomy_differences' => [[
                'dimension' => 'relationships',
                'action' => 'remove',
                'taxonomy' => ['id' => $attached['relationship']->id, 'name' => $attached['relationship']->name, 'slug' => $attached['relationship']->slug],
                'severity' => 'material',
                'forces_human_review' => true,
                'reason' => 'Brother is misleading on a targeted landing page.',
            ]],
            'semantic_evaluation' => [
                'why_this_gift' => 'A useful everyday gift.',
                'current_taxonomy_evaluations' => [
                    'relationships' => [[
                        'id' => $attached['relationship']->id,
                        'name' => $attached['relationship']->name,
                        'slug' => $attached['relationship']->slug,
                        'strength' => 'weak',
                        'reason' => 'Not a brother-specific gift.',
                        'misleading_on_targeted_landing_page' => true,
                    ]],
                    'occasions' => [],
                    'interests' => [],
                    'gift_types' => [],
                ],
                'taxonomy_suggestions' => [
                    'relationships' => [],
                    'occasions' => [],
                    'interests' => [[
                        'id' => 99,
                        'name' => 'Travel',
                        'slug' => 'travel',
                        'strength' => 'medium',
                        'reason' => 'Plausible but not required.',
                    ]],
                    'gift_types' => [],
                ],
            ],
        ]);

        Livewire::test(ReviewHumanCuration::class, ['record' => $audit->product->getRouteKey()])
            ->assertOk()
            ->assertSee('P2 taxonomy review')
            ->assertSee('Current taxonomy')
            ->assertSee('Material findings')
            ->assertSee('Suggested taxonomy')
            ->assertSee('Authoritative applicability')
            ->assertSee($attached['relationship']->name)
            ->assertSee('Travel')
            ->fillForm([
                'decision' => ProductCurationDecision::Reclassify->value,
                'reason_codes' => [ProductCurationDecisionReasonCode::TaxonomyRelationshipIssue->value],
                'reason_notes' => 'Brother would mislead a shopper on a brother landing page.',
                'taxonomy_relationships_remove' => [$attached['relationship']->id],
            ])
            ->assertSee('- '.$attached['relationship']->name)
            ->call('saveDecision')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('product_curation_decisions', [
            'product_id' => $audit->product_id,
            'decision' => ProductCurationDecision::Reclassify->value,
            'remediation_status' => ProductCurationRemediationStatus::Pending->value,
        ]);
        $this->assertSame(1, $audit->product->fresh()->relationships()->count());

        Livewire::test(ReviewHumanCuration::class, ['record' => $audit->product->getRouteKey()])
            ->assertSee('Remediation: Pending')
            ->call('executeRemediation');

        $product = $audit->product->fresh();
        $this->assertSame(0, $product->relationships()->count());
        $this->assertSame(TaxonomyClassificationStatus::HumanOverridden, $product->taxonomy_classification_status);
        $this->assertSame(ProductStatus::Published, $product->status);
        $this->assertDatabaseHas('product_curation_decisions', [
            'product_id' => $audit->product_id,
            'decision' => ProductCurationDecision::Reclassify->value,
            'remediation_status' => ProductCurationRemediationStatus::Completed->value,
        ]);
    }

    public function test_p2_keep_and_defer_do_not_mutate_taxonomy(): void
    {
        $this->actingAs(User::factory()->create());
        $run = $this->acceptedCurationRun();
        $keep = $this->curatedAudit($run, Product::factory()->create(['name' => 'Keep Taxonomy Gift']), [
            'taxonomy_differences' => [[
                'dimension' => 'relationships',
                'severity' => 'material',
                'forces_human_review' => true,
                'taxonomy' => ['name' => 'Husband'],
                'reason' => 'Audit flagged Husband.',
            ]],
        ]);
        $defer = $this->curatedAudit($run, Product::factory()->create(['name' => 'Defer Taxonomy Gift']), [
            'taxonomy_differences' => [[
                'dimension' => 'interests',
                'severity' => 'material',
                'forces_human_review' => true,
                'taxonomy' => ['name' => 'Gaming'],
                'reason' => 'Missing a better interest.',
            ]],
        ]);
        $keepAttached = $this->attachMerchandisingTaxonomy($keep->product);

        Livewire::test(ReviewHumanCuration::class, ['record' => $keep->product->getRouteKey()])
            ->fillForm([
                'decision' => ProductCurationDecision::Keep->value,
                'reason_codes' => [
                    ProductCurationDecisionReasonCode::StrongPracticalValue->value,
                    ProductCurationDecisionReasonCode::AuditAnomaly->value,
                ],
                'reason_notes' => 'Current taxonomy still has merchandising value.',
            ])
            ->call('saveDecision')
            ->assertHasNoFormErrors();

        Livewire::test(ReviewHumanCuration::class, ['record' => $defer->product->getRouteKey()])
            ->fillForm([
                'decision' => ProductCurationDecision::Defer->value,
                'reason_codes' => [ProductCurationDecisionReasonCode::NeedsMoreResearch->value],
                'reason_notes' => 'Cannot safely invent a missing interest.',
            ])
            ->call('saveDecision')
            ->assertHasNoFormErrors();

        $this->assertSame($keepAttached['relationship']->id, $keep->product->fresh()->relationships()->first()?->id);
        $this->assertDatabaseHas('product_curation_decisions', [
            'product_id' => $keep->product_id,
            'decision' => ProductCurationDecision::Keep->value,
            'remediation_status' => ProductCurationRemediationStatus::NotRequired->value,
        ]);
        $this->assertDatabaseHas('product_curation_decisions', [
            'product_id' => $defer->product_id,
            'decision' => ProductCurationDecision::Defer->value,
        ]);
    }

    public function test_p3_review_shows_quality_framework_and_requires_a_retention_reason(): void
    {
        $this->actingAs(User::factory()->create());
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run, Product::factory()->create(['name' => 'Borderline Quality Gift']), [
            'gift_score' => 58,
            'catalog_value_score' => 41,
            'gift_intents' => ['practical'],
            'strongest_fits' => [
                'relationships' => ['name' => 'Husband', 'strength' => 'medium'],
                'occasions' => ['name' => 'Birthday', 'strength' => 'medium'],
                'interests' => ['name' => 'Travel', 'strength' => 'strong'],
                'gift_types' => ['name' => 'Experience', 'strength' => 'weak'],
            ],
        ]);

        Livewire::test(ListHumanCuration::class)
            ->assertSee('P3-A Gift Score')
            ->assertSee('FEATURE:');

        Livewire::test(ReviewHumanCuration::class, ['record' => $audit->product->getRouteKey()])
            ->assertOk()
            ->assertSee('P3 quality review')
            ->assertSee(P3QualitySubgroup::LowGiftScore->getLabel())
            ->assertSee('Catalog role')
            ->assertSee('Strongest:')
            ->assertSee('Husband')
            ->assertSee('Travel')
            ->fillForm([
                'decision' => ProductCurationDecision::Keep->value,
                'reason_codes' => [ProductCurationDecisionReasonCode::AuditAnomaly->value],
                'reason_notes' => 'Looks acceptable.',
            ])
            ->call('saveDecision')
            ->assertHasFormErrors(['reason_codes']);

        Livewire::test(ReviewHumanCuration::class, ['record' => $audit->product->getRouteKey()])
            ->fillForm([
                'decision' => ProductCurationDecision::Keep->value,
                'reason_codes' => [ProductCurationDecisionReasonCode::FillsCatalogGap->value],
                'reason_notes' => 'Fills a travel-gift gap at this budget.',
            ])
            ->call('saveDecision')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('product_curation_decisions', [
            'product_id' => $audit->product_id,
            'decision' => ProductCurationDecision::Keep->value,
        ]);
    }

    public function test_review_page_shows_live_current_merchant_observation_without_rewriting_the_audit_snapshot(): void
    {
        $this->actingAs(User::factory()->create());
        $run = $this->acceptedCurationRun();
        $product = Product::factory()->create([
            'name' => 'PlayStation 5 Console – Fortnite Flowering Chaos Bundle',
            'price_amount' => null,
            'price_currency' => 'INR',
        ]);
        $merchant = Merchant::query()->create([
            'name' => 'Amazon India',
            'slug' => 'amazon-in-live-evidence-'.uniqid(),
            'affiliate_network' => 'amazon',
            'is_active' => true,
        ]);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://www.amazon.in/dp/B0G66KXDKQ',
            'external_product_id' => 'B0G66KXDKQ',
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
            'availability' => 'out_of_stock',
            'last_seen_at' => Carbon::parse('2026-09-14T04:55:59+00:00'),
            'last_verified_at' => Carbon::parse('2026-09-14T04:55:59+00:00'),
        ]);
        $audit = $this->curatedAudit($run, $product, [
            'gift_score' => 67,
            'catalog_value_score' => null,
            'issues' => [[
                'code' => 'missing_commerce_evidence',
                'severity' => 'warning',
                'message' => 'Material commerce evidence is missing.',
                'context' => ['missing' => ['price', 'value_for_money_assessment']],
            ]],
            'evidence_snapshot' => [
                'product_id' => $product->id,
                'name' => $product->name,
                'status' => $product->status->value,
                'price' => ['amount' => null, 'currency' => 'INR', 'compare_at_amount' => null],
                'taxonomy' => [
                    'relationships' => [],
                    'occasions' => [],
                    'interests' => [],
                    'gift_types' => [],
                ],
                'offers' => [[
                    'status' => 'active',
                    'merchant' => ['name' => 'Amazon India', 'slug' => 'amazon-in'],
                    'is_primary' => true,
                    'availability' => 'out_of_stock',
                    'last_seen_at' => '2026-09-08T07:33:36+00:00',
                    'last_verified_at' => '2026-09-08T07:33:36+00:00',
                    'external_product_id' => 'B0G66KXDKQ',
                ]],
                'images' => [],
                'provenance' => [],
            ],
        ]);

        $case = app(BuildHumanCurationReviewAction::class)->execute($product->fresh());
        $this->assertNotNull($case);
        $this->assertNull($case->evidence['price']);
        $this->assertSame('2026-09-08T07:33:36+00:00', $case->evidence['offer_freshness']);
        $this->assertSame(['price', 'value_for_money_assessment'], $case->evidence['missing']);
        $this->assertNull($case->evidence['current']['price']);
        $this->assertSame('out_of_stock', $case->evidence['current']['availability']);
        $this->assertSame('B0G66KXDKQ', $case->evidence['current']['external_product_id']);
        $this->assertTrue($case->evidence['current']['exact_identity_present']);
        $this->assertSame('2026-09-14T04:55:59+00:00', $case->evidence['current']['last_seen_at']);

        Livewire::test(ReviewHumanCuration::class, ['record' => $audit->product->getRouteKey()])
            ->assertOk()
            ->assertSee('Current merchant observation')
            ->assertSee('Live Product / offer fields')
            ->assertSee('2026-09-14T04:55:59+00:00')
            ->assertSee('2026-09-08T07:33:36+00:00')
            ->assertSee('B0G66KXDKQ')
            ->assertSee('Out Of Stock');
    }
}
