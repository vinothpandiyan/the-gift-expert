<?php

namespace App\Actions\CuratedCatalog;

use App\CommercialSourcing\CommercialEnrichmentException;
use App\CommercialSourcing\OpenAiCompatibleCommercialEnrichmentClient;
use App\CuratedCatalog\CuratedEditorialCopyPrompt;
use App\Enums\EditorialOwnership;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class RefreshCuratedProductEditorialCopyAction
{
    public function __construct(
        private BuildCuratedMerchantProductInputFromProductAction $buildInput,
        private BuildCuratedTaxonomyContentFingerprintAction $contentFingerprint,
        private CuratedEditorialCopyPrompt $prompt,
        private OpenAiCompatibleCommercialEnrichmentClient $client,
    ) {}

    /**
     * @return array{changed: bool, name: string, short_description: ?string, description: ?string}
     */
    public function execute(Product $product): array
    {
        $product = $product->fresh() ?? $product;

        if ($product->status !== ProductStatus::Draft) {
            throw new CommercialEnrichmentException('Editorial backfill only accepts draft products.');
        }

        if (! $product->editorialCopyNeedsAiGeneration()) {
            throw new CommercialEnrichmentException('Editorial copy is human-owned or already uses the current AI generation.');
        }

        [$merchant] = $this->buildInput->execute($product);
        $sourceTitle = $this->contentFingerprint->sourceTitle($product);
        $fingerprintBefore = $product->taxonomy_content_fingerprint;
        $statusBefore = $product->taxonomy_classification_status;
        $publicationBefore = $product->status;
        $previousName = $product->name;
        $previousShort = $product->short_description;
        $previousDescription = $product->description;

        $messages = $this->prompt->messages(
            $sourceTitle,
            (string) $merchant->name,
            $product->name,
            is_string($product->short_description) ? $product->short_description : null,
            is_string($product->description) ? $product->description : null,
        );

        $decoded = $this->client->complete($messages['system'], $messages['user'], $messages['schema'], requireTaxonomy: false);
        $name = $this->nullableString($decoded['name'] ?? null) ?? $sourceTitle;

        if ($name === '') {
            throw new CommercialEnrichmentException('The editorial copy response was malformed.');
        }

        $shortDescription = $this->nullableString($decoded['short_description'] ?? null);
        $description = $this->nullableString($decoded['description'] ?? null);

        $product = DB::transaction(function () use ($product, $name, $shortDescription, $description): Product {
            $fresh = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($fresh->status !== ProductStatus::Draft || ! $fresh->editorialCopyNeedsAiGeneration()) {
                throw new CommercialEnrichmentException('Editorial ownership changed while copy was being generated.');
            }

            $fresh->name = $name;
            $fresh->short_description = $shortDescription;
            $fresh->description = $description;
            $fresh->editorial_ownership = EditorialOwnership::Ai;
            $fresh->editorial_generation_version = (int) config('curated_catalog.editorial_copy.version', 1);
            $fresh->editorial_reviewed_at = null;
            $fresh->editorial_reviewed_by_user_id = null;
            $fresh->save();

            return $fresh;
        });

        $fresh = $product->fresh() ?? $product;

        if ($fresh->taxonomy_content_fingerprint !== $fingerprintBefore
            || $fresh->taxonomy_classification_status !== $statusBefore
            || $fresh->status !== $publicationBefore) {
            throw new CommercialEnrichmentException('Editorial copy refresh must not change classification or publication.');
        }

        return [
            'changed' => $fresh->name !== $previousName
                || $fresh->short_description !== $previousShort
                || $fresh->description !== $previousDescription,
            'name' => $fresh->name,
            'short_description' => $fresh->short_description,
            'description' => $fresh->description,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
