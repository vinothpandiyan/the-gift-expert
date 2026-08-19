<?php

namespace App\Actions\Product;

use App\Enums\AffiliateLinkStatus;
use App\Models\Product;

class AssessProductPublicationRequirementsAction
{
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

        if (config('gift_publication.warnings.primary_category') && ! $product->categories()
            ->wherePivot('is_primary', true)
            ->exists()) {
            $warnings[] = 'missing_primary_category';
        }

        $errorMessages = array_map(
            fn (string $code): string => $this->errorMessage($code),
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

    private function errorMessage(string $code): string
    {
        return match ($code) {
            'missing_name' => 'A gift name is required before publishing.',
            'missing_slug' => 'A gift slug is required before publishing.',
            'no_image' => 'Add at least one gift image before publishing.',
            'no_active_affiliate_link' => 'Add at least one active affiliate link before publishing.',
            default => 'Publication requirement failed: '.$code,
        };
    }
}
