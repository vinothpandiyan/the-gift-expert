<?php

namespace App\Actions\LaunchPublication;

use App\Actions\CatalogCuration\ResolveEffectiveProductCurationDecisionAction;
use App\Actions\Product\PublishProductsAction;
use App\Enums\ProductStatus;
use App\LaunchPublication\LaunchPublicationAttempt;
use App\Models\Product;
use Illuminate\Support\Collection;

class PublishLaunchCatalogCohortAction
{
    public function __construct(
        private ResolveEffectiveProductCurationDecisionAction $resolveDecision,
        private PublishProductsAction $publishProducts,
    ) {}

    /**
     * Re-check editorial eligibility, then publish each remaining Product through
     * PublishProductsAction / PublishProductAction. Readiness is not reimplemented here.
     *
     * @param  list<int>  $productIds
     * @return Collection<int, LaunchPublicationAttempt>
     */
    public function execute(array $productIds, string $actor = 'console:catalog:launch-publication'): Collection
    {
        $attempts = collect();
        $eligible = [];

        foreach (array_values(array_unique(array_map('intval', $productIds))) as $productId) {
            $product = Product::query()->find($productId);

            if (! $product instanceof Product) {
                $attempts->push(LaunchPublicationAttempt::failed($productId, 'missing_product'));

                continue;
            }

            $title = (string) $product->name;
            $decision = $this->resolveDecision->execute($product);
            $decisionValue = $decision?->decision?->value;
            $previous = $product->status?->value;

            if ($product->status === ProductStatus::Archived) {
                $attempts->push(LaunchPublicationAttempt::skipped(
                    $productId,
                    'archived',
                    $title,
                    $decisionValue,
                    $previous,
                ));

                continue;
            }

            if ($product->status !== ProductStatus::Draft) {
                $attempts->push(LaunchPublicationAttempt::skipped(
                    $productId,
                    $product->status === ProductStatus::Published ? 'already_published' : 'not_draft',
                    $title,
                    $decisionValue,
                    $previous,
                ));

                continue;
            }

            if ($decision === null || ! $decision->decision?->isKeepFamily()) {
                $attempts->push(LaunchPublicationAttempt::skipped(
                    $productId,
                    'not_editorially_approved',
                    $title,
                    $decisionValue,
                    $previous,
                ));

                continue;
            }

            $eligible[] = $product;
        }

        $published = $this->publishProducts->execute($eligible, $actor);

        foreach ($published as $attempt) {
            $product = Product::query()->find($attempt->productId);
            $decision = $product instanceof Product ? $this->resolveDecision->execute($product) : null;

            $attempts->push(new LaunchPublicationAttempt(
                productId: $attempt->productId,
                result: $attempt->result,
                reason: $attempt->reason,
                title: $attempt->title,
                humanDecision: $decision?->decision?->value,
                previousStatus: $attempt->previousStatus,
                newStatus: $attempt->newStatus,
                publishedAt: $attempt->publishedAt,
                actor: $attempt->actor,
                warnings: $attempt->warnings,
            ));
        }

        return $attempts->values();
    }
}
