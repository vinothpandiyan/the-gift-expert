<?php

namespace Tests\Support;

use App\Actions\CatalogCuration\RecordProductCurationDecisionAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\CatalogRole;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationDecisionReasonCode;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\AffiliateLink;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use App\Models\ProductCurationDecision as ProductCurationDecisionRecord;
use App\Models\ProductImage;
use App\Models\Relationship;
use App\Models\User;

trait BuildsLaunchPublicationFixtures
{
    use BuildsHumanCurationFixtures;

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function publishableDraft(array $overrides = []): Product
    {
        $merchant = Merchant::query()->create([
            'name' => 'Launch Merchant',
            'slug' => 'launch-merchant-'.uniqid(),
            'affiliate_network' => 'example',
        ]);

        $category = Category::query()->create([
            'name' => 'Home',
            'slug' => 'home-'.uniqid(),
            'is_active' => true,
        ]);

        $product = Product::factory()->create(array_merge([
            'status' => ProductStatus::Draft,
            'price_amount' => '999.00',
            'price_currency' => 'INR',
            'taxonomy_classification_status' => TaxonomyClassificationStatus::AiAccepted,
        ], $overrides));

        $product->categories()->sync([
            $category->id => ['is_primary' => true],
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'images/launch-'.$product->id.'.jpg',
            'is_primary' => true,
        ]);

        AffiliateLink::query()->create([
            'product_id' => $product->id,
            'merchant_id' => $merchant->id,
            'url' => 'https://example.com/launch-'.$product->id,
            'status' => AffiliateLinkStatus::Active,
            'is_primary' => true,
            'availability' => 'in_stock',
        ]);

        return $product->fresh();
    }

    protected function recordLaunchDecision(
        Product $product,
        ProductCurationAudit $audit,
        ProductCurationDecision $decision,
        ?CatalogRole $role = null,
        ?string $notes = 'Launch curation decision.',
    ): ProductCurationDecisionRecord {
        $reason = match ($decision) {
            ProductCurationDecision::RemoveCandidate => ProductCurationDecisionReasonCode::WeakGiftFit,
            ProductCurationDecision::Defer => ProductCurationDecisionReasonCode::NeedsMoreResearch,
            ProductCurationDecision::Deactivate => ProductCurationDecisionReasonCode::WeakGiftFit,
            default => ProductCurationDecisionReasonCode::StrongGift,
        };

        return app(RecordProductCurationDecisionAction::class)->execute(
            product: $product,
            audit: $audit,
            decision: $decision,
            reasonCodes: [$reason],
            reasonNotes: $notes,
            user: User::factory()->create(),
            catalogRole: $role,
        );
    }

    protected function attachRelationship(Product $product, string $name = 'Husband'): Relationship
    {
        $relationship = Relationship::query()->create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'is_active' => true,
        ]);
        $product->relationships()->sync([$relationship->id]);

        return $relationship;
    }

    /**
     * @return array{run: ProductCurationAuditRun, product: Product, audit: ProductCurationAudit}
     */
    protected function retainedDraft(
        ProductCurationDecision $decision = ProductCurationDecision::Keep,
        array $productOverrides = [],
        ?ProductCurationAuditRun $run = null,
        ?CatalogRole $role = null,
    ): array {
        $run ??= $this->acceptedCurationRun();
        $product = $this->publishableDraft($productOverrides);
        $audit = $this->curatedAudit($run, $product);
        $this->recordLaunchDecision($product, $audit, $decision, $role);

        return [
            'run' => $run,
            'product' => $product->fresh(['currentCurationDecision']),
            'audit' => $audit,
        ];
    }
}
