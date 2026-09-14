<?php

namespace Tests\Feature\CatalogCuration;

use App\Actions\CatalogCuration\DiagnoseP0IntegrityAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\P0IntegrityDiagnosis;
use App\Models\AffiliateLink;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsHumanCurationFixtures;
use Tests\TestCase;

class P0IntegrityDiagnosisTest extends TestCase
{
    use BuildsHumanCurationFixtures;
    use RefreshDatabase;

    public function test_it_classifies_a_development_placeholder_as_a_catalog_defect(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run, Product::factory()->create([
            'name' => 'Draft Gift Idea',
            'slug' => 'draft-gift-idea',
            'short_description' => 'Sample draft gift for local development.',
            'price_amount' => null,
        ]), [
            'gift_score' => 0,
            'catalog_value_score' => 0,
            'issues' => [[
                'code' => 'missing_commerce_evidence',
                'severity' => 'warning',
                'message' => 'Commerce evidence is missing.',
                'context' => [],
            ]],
        ]);

        $result = app(DiagnoseP0IntegrityAction::class)->execute($audit->product->fresh(), $audit);

        $this->assertSame(P0IntegrityDiagnosis::CatalogDefect, $result->diagnosis);
        $this->assertContains('catalog:development_or_test_placeholder', $result->signals);
    }

    public function test_it_classifies_unresolved_audit_labels_on_a_valid_product_as_an_anomaly(): void
    {
        $run = $this->acceptedCurationRun();
        $product = $this->usableProduct('Leather Journal');
        $audit = $this->curatedAudit($run, $product, [
            'gift_score' => 72,
            'catalog_value_score' => 61,
            'issues' => [[
                'code' => 'unresolved_taxonomy_label',
                'severity' => 'blocking',
                'message' => 'A taxonomy label could not be resolved.',
                'context' => [],
            ]],
            'taxonomy_differences' => [[
                'cause' => 'unresolved_taxonomy_label',
                'dimension' => 'relationships',
                'severity' => 'blocking',
            ]],
        ]);

        $result = app(DiagnoseP0IntegrityAction::class)->execute($product->fresh(), $audit);

        $this->assertSame(P0IntegrityDiagnosis::AuditAnomaly, $result->diagnosis);
        $this->assertContains('audit:issue:unresolved_taxonomy_label', $result->signals);
        $this->assertContains('audit:taxonomy:unresolved_taxonomy_label', $result->signals);
    }

    public function test_it_does_not_infer_a_decision_from_diagnosis(): void
    {
        $run = $this->acceptedCurationRun();
        $audit = $this->curatedAudit($run, Product::factory()->create(['name' => 'Ambiguous Gift']));

        $result = app(DiagnoseP0IntegrityAction::class)->execute($audit->product->fresh(), $audit);

        $this->assertContains($result->diagnosis, P0IntegrityDiagnosis::cases());
        $this->assertSame(0, $audit->product->fresh()->curationDecisions()->count());
        $this->assertSame('draft', $audit->product->fresh()->status->value);
    }

    private function usableProduct(string $name): Product
    {
        $product = Product::factory()->create([
            'name' => $name,
            'price_amount' => '1499.00',
        ]);
        $category = Category::query()->create([
            'name' => 'Stationery',
            'slug' => 'stationery-'.uniqid(),
            'is_active' => true,
        ]);
        $merchant = Merchant::query()->create([
            'name' => 'Diagnosis Merchant',
            'slug' => 'diagnosis-merchant-'.uniqid(),
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

        return $product->fresh();
    }
}
