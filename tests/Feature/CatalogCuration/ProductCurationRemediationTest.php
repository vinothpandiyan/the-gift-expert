<?php

namespace Tests\Feature\CatalogCuration;

use App\Actions\CatalogCuration\DeactivateProductFromCurationAction;
use App\Actions\CatalogCuration\RecordProductCurationDecisionAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationDecisionReasonCode;
use App\Enums\ProductCurationRemediationStatus;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationDecision as ProductCurationDecisionRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsHumanCurationFixtures;
use Tests\TestCase;

class ProductCurationRemediationTest extends TestCase
{
    use BuildsHumanCurationFixtures;
    use RefreshDatabase;

    public function test_deactivate_archives_the_product_after_an_explicit_decision(): void
    {
        Log::spy();
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run, Product::factory()->create([
            'status' => ProductStatus::Published,
            'name' => 'Redundant Wallet',
        ]));
        $this->attachProtectedCatalog($audit->product);
        $before = $this->protectedState($audit->product_id, $audit->id);

        $decision = app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Deactivate,
            reasonCodes: [ProductCurationDecisionReasonCode::RedundantConcept],
            reasonNotes: 'Interchangeable with a stronger wallet peer and should leave the live catalog.',
            user: User::factory()->create(),
        );

        $this->assertSame(ProductCurationRemediationStatus::Completed, $decision->remediation_status);
        $this->assertSame(ProductStatus::Archived, $audit->product->fresh()->status);
        $this->assertSame(1, ProductCurationDecisionRecord::query()->count());
        $this->assertSame(1, ProductCurationAudit::query()->count());

        $after = $this->protectedState($audit->product_id, $audit->id);
        $this->assertEquals($before['categories'], $after['categories']);
        $this->assertEquals($before['offers'], $after['offers']);
        $this->assertEquals($before['audit'], $after['audit']);
        $this->assertNotSame($before['product']->status, $after['product']->status);
        Log::shouldHaveReceived('info')->withArgs(fn (string $event): bool => $event === 'catalog_curation.product_deactivated');
    }

    public function test_keep_and_reclassify_do_not_archive_or_change_taxonomy(): void
    {
        $run = $this->acceptedCurationRun();
        $keep = $this->curatedAudit($run, Product::factory()->create(['status' => ProductStatus::Published]));
        $reclassify = $this->curatedAudit($run, Product::factory()->create(['status' => ProductStatus::Published]));
        $this->attachProtectedCatalog($keep->product);
        $attached = $this->attachMerchandisingTaxonomy($reclassify->product);
        $user = User::factory()->create();

        $keepDecision = app(RecordProductCurationDecisionAction::class)->execute(
            product: $keep->product,
            audit: $keep,
            decision: ProductCurationDecision::Keep,
            reasonCodes: [
                ProductCurationDecisionReasonCode::StrongPracticalValue,
                ProductCurationDecisionReasonCode::AuditAnomaly,
            ],
            reasonNotes: 'Audit labels are unresolved, but current assignments are valid.',
            user: $user,
        );
        $reclassifyDecision = app(RecordProductCurationDecisionAction::class)->execute(
            product: $reclassify->product,
            audit: $reclassify,
            decision: ProductCurationDecision::Reclassify,
            reasonCodes: [ProductCurationDecisionReasonCode::TaxonomyRelationshipIssue],
            reasonNotes: 'Relationship needs later remediation.',
            user: $user,
            taxonomyProposal: $this->taxonomyProposal(remove: [$attached['relationship']->id]),
        );

        $this->assertSame(ProductCurationRemediationStatus::NotRequired, $keepDecision->remediation_status);
        $this->assertSame(ProductCurationRemediationStatus::Pending, $reclassifyDecision->remediation_status);
        $this->assertSame(ProductStatus::Published, $keep->product->fresh()->status);
        $this->assertSame(ProductStatus::Published, $reclassify->product->fresh()->status);
        $this->assertSame(1, $reclassify->product->categories()->count());
    }

    public function test_remove_candidate_stays_pending_without_archiving(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run, Product::factory()->create(['status' => ProductStatus::Published]));

        $decision = app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::RemoveCandidate,
            reasonCodes: [ProductCurationDecisionReasonCode::WeakerThanPeer],
            reasonNotes: 'Weaker than the retained premium pick, but leave live until a later pass.',
            user: User::factory()->create(),
        );

        $this->assertSame(ProductCurationRemediationStatus::Pending, $decision->remediation_status);
        $this->assertSame(ProductStatus::Published, $audit->product->fresh()->status);
    }

    public function test_deactivation_cannot_run_without_a_current_deactivate_decision(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);
        $keep = app(RecordProductCurationDecisionAction::class)->execute(
            product: $audit->product,
            audit: $audit,
            decision: ProductCurationDecision::Keep,
            reasonCodes: [ProductCurationDecisionReasonCode::StrongGift],
            reasonNotes: null,
            user: User::factory()->create(),
        );

        $this->expectException(ValidationException::class);

        app(DeactivateProductFromCurationAction::class)->execute($keep);
    }

    /**
     * @return array<string, mixed>
     */
    private function protectedState(int $productId, int $auditId): array
    {
        return [
            'product' => DB::table('products')->where('id', $productId)->first(),
            'categories' => DB::table('category_product')->where('product_id', $productId)->orderBy('category_id')->get()->all(),
            'offers' => DB::table('affiliate_links')->where('product_id', $productId)->orderBy('id')->get()->all(),
            'audit' => DB::table('product_curation_audits')->where('id', $auditId)->first(),
        ];
    }

    private function attachProtectedCatalog(Product $product): void
    {
        $category = Category::query()->create([
            'name' => 'Accessories',
            'slug' => 'accessories-'.uniqid(),
            'is_active' => true,
        ]);
        $merchant = Merchant::query()->create([
            'name' => 'Remediation Merchant',
            'slug' => 'remediation-merchant-'.uniqid(),
            'affiliate_network' => 'example',
            'is_active' => true,
        ]);

        $product->categories()->attach($category->id, ['is_primary' => true]);
        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://merchant.example/'.$product->id,
            'external_product_id' => 'SKU-'.$product->id,
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
        ]);
    }
}
