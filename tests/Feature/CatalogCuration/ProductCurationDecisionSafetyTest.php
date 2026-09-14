<?php

namespace Tests\Feature\CatalogCuration;

use App\Actions\CatalogCuration\RecordProductCurationDecisionAction;
use App\Enums\EditorialOwnership;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationDecisionReasonCode;
use App\Enums\ProductStatus;
use App\Enums\SeoOwnership;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\Category;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\ProductCurationAudit;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsHumanCurationFixtures;
use Tests\TestCase;

class ProductCurationDecisionSafetyTest extends TestCase
{
    use BuildsHumanCurationFixtures;
    use RefreshDatabase;

    public function test_recording_a_decision_does_not_mutate_protected_catalog_state(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run);
        $product = $audit->product;
        $category = Category::query()->create(['name' => 'Tech', 'slug' => 'tech', 'is_active' => true]);
        $relationship = Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true]);
        $occasion = Occasion::query()->create(['name' => 'Birthday', 'slug' => 'birthday', 'is_active' => true]);
        $interest = Interest::query()->create(['name' => 'Travel', 'slug' => 'travel', 'is_active' => true]);

        $product->update([
            'status' => ProductStatus::Draft,
            'editorial_ownership' => EditorialOwnership::Human,
            'seo_ownership' => SeoOwnership::Human,
            'taxonomy_classification_status' => TaxonomyClassificationStatus::HumanApproved,
            'meta_title' => 'Protected title',
            'short_description' => 'Protected copy',
        ]);
        $product->categories()->attach($category->id, ['is_primary' => true]);
        $product->relationships()->attach($relationship->id);
        $product->occasions()->attach($occasion->id);
        $product->interests()->attach($interest->id);

        $before = $this->protectedState($product->id, $audit->id);

        app(RecordProductCurationDecisionAction::class)->execute(
            product: $product,
            audit: $audit,
            decision: ProductCurationDecision::Keep,
            reasonCodes: [ProductCurationDecisionReasonCode::StrongGift],
            reasonNotes: 'Recorded intention only.',
            user: User::factory()->create(),
        );

        $this->assertEquals($before, $this->protectedState($product->id, $audit->id));
        $this->assertSame(1, DB::table('product_curation_decisions')->count());
        $this->assertSame(1, ProductCurationAudit::query()->count());
        $this->assertSame(1, $run->fresh()->audits()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function protectedState(int $productId, int $auditId): array
    {
        return [
            'product' => DB::table('products')->where('id', $productId)->first(),
            'categories' => DB::table('category_product')->where('product_id', $productId)->orderBy('category_id')->get()->all(),
            'relationships' => DB::table('relationship_product')->where('product_id', $productId)->orderBy('relationship_id')->get()->all(),
            'occasions' => DB::table('occasion_product')->where('product_id', $productId)->orderBy('occasion_id')->get()->all(),
            'interests' => DB::table('interest_product')->where('product_id', $productId)->orderBy('interest_id')->get()->all(),
            'gift_types' => DB::table('gift_type_product')->where('product_id', $productId)->orderBy('gift_type_id')->get()->all(),
            'images' => DB::table('product_images')->where('product_id', $productId)->orderBy('id')->get()->all(),
            'offers' => DB::table('affiliate_links')->where('product_id', $productId)->orderBy('id')->get()->all(),
            'provenance' => DB::table('catalog_product_sources')->orderBy('id')->get()->all(),
            'intake_items' => DB::table('curated_product_intake_items')->orderBy('id')->get()->all(),
            'wishlist_lists' => DB::table('catalog_source_lists')->orderBy('id')->get()->all(),
            'import_items' => DB::table('import_run_items')->orderBy('id')->get()->all(),
            'audit' => DB::table('product_curation_audits')->where('id', $auditId)->first(),
        ];
    }
}
