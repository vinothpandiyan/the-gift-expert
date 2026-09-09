<?php

namespace App\Actions\CuratedCatalog;

use App\CommercialSourcing\CommercialEnrichmentException;
use App\CommercialSourcing\OpenAiCompatibleCommercialEnrichmentClient;
use App\CuratedCatalog\CuratedEditorialCopyPrompt;
use App\Models\Product;

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

        $product->name = $name;
        $product->short_description = $shortDescription;
        $product->description = $description;
        $product->save();

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
