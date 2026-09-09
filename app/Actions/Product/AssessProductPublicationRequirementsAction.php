<?php

namespace App\Actions\Product;

use App\Actions\Category\IsAcceptableMerchandisingCategoryAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Models\Product;

class AssessProductPublicationRequirementsAction
{
    public function __construct(
        private IsAcceptableMerchandisingCategoryAction $isAcceptableMerchandisingCategory,
    ) {}

    /**
     * @return array{error_codes: list<string>, warnings: list<string>, error_messages: list<string>, warning_messages: list<string>}
     */
    public function execute(Product $product): array
    {
        $errorCodes = [];
        $warnings = [];

        if (config('gift_publication.requirements.name') && blank($product->name)) {
            $errorCodes[] = 'missing_name';
        }

        if (config('gift_publication.requirements.slug') && blank($product->slug)) {
            $errorCodes[] = 'missing_slug';
        }

        if (config('gift_publication.requirements.image') && ! $product->images()->exists()) {
            $errorCodes[] = 'no_image';
        }

        if (config('gift_publication.requirements.active_affiliate_link') && ! $product->affiliateLinks()
            ->where('status', AffiliateLinkStatus::Active)
            ->exists()) {
            $errorCodes[] = 'no_active_affiliate_link';
        }

        if (config('gift_publication.warnings.price_amount') && $product->price_amount === null) {
            $warnings[] = 'missing_or_ambiguous_price';
        }

        $primary = $product->categories()
            ->wherePivot('is_primary', true)
            ->first();

        if (config('gift_publication.requirements.primary_category')) {
            if ($primary === null) {
                $errorCodes[] = 'missing_primary_category';
            } elseif (! $this->isAcceptableMerchandisingCategory->execute((int) $primary->id)) {
                $errorCodes[] = 'invalid_primary_category';
            }
        } elseif (config('gift_publication.warnings.primary_category') && $primary === null) {
            $warnings[] = 'missing_primary_category';
        }

        if (config('gift_publication.requirements.classification_status')) {
            $status = $product->taxonomy_classification_status ?? TaxonomyClassificationStatus::None;

            if ($status->blocksPublication()) {
                $errorCodes[] = 'classification_not_publishable';
            }
        }

        $errorMessages = array_map(
            fn (string $code): string => $this->errorMessage($code, $product),
            $errorCodes,
        );

        $warningMessages = array_map(
            fn (string $code): string => $this->warningMessage($code),
            $warnings,
        );

        return [
            'error_codes' => $errorCodes,
            'warnings' => $warnings,
            'error_messages' => $errorMessages,
            'warning_messages' => $warningMessages,
        ];
    }

    private function warningMessage(string $code): string
    {
        return match ($code) {
            'missing_or_ambiguous_price' => 'This gift has no price amount set.',
            'missing_primary_category' => 'No primary gift category is assigned.',
            default => 'Publication warning: '.$code,
        };
    }

    private function errorMessage(string $code, Product $product): string
    {
        return match ($code) {
            'missing_name' => 'A gift name is required before publishing.',
            'missing_slug' => 'A gift slug is required before publishing.',
            'no_image' => 'Add at least one gift image before publishing.',
            'no_active_affiliate_link' => 'Add at least one active affiliate link before publishing.',
            'missing_primary_category' => 'Assign an active primary merchandising category before publishing.',
            'invalid_primary_category' => 'The primary category is not an active merchandising category.',
            'classification_not_publishable' => $this->classificationMessage($product),
            default => 'Publication requirement failed: '.$code,
        };
    }

    private function classificationMessage(Product $product): string
    {
        $status = $product->taxonomy_classification_status ?? TaxonomyClassificationStatus::None;

        return match ($status) {
            TaxonomyClassificationStatus::Review => 'Approve or override taxonomy classification before publishing. This gift is still in review.',
            TaxonomyClassificationStatus::Failed => 'Classification failed. Assign taxonomy manually or reclassify before publishing.',
            TaxonomyClassificationStatus::AiProposed => 'The AI proposal is not applied yet. Approve, override, or reclassify before publishing.',
            TaxonomyClassificationStatus::None => 'This gift is unclassified. Assign taxonomy or run classification before publishing.',
            default => 'Taxonomy classification is not ready for publication.',
        };
    }
}
