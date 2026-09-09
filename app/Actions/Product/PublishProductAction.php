<?php

namespace App\Actions\Product;

use App\Enums\EditorialOwnership;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PublishProductAction
{
    public function __construct(
        private AssessProductPublicationRequirementsAction $assessPublicationRequirements,
        private NormalizeProductCategoryAssignmentsAction $normalizeCategories,
    ) {}

    /**
     * @return array{warnings: list<string>}
     */
    public function execute(Product $product): array
    {
        $assessment = $this->assessPublicationRequirements->execute($product);

        if ($assessment['error_codes'] !== []) {
            throw ValidationException::withMessages([
                'status' => $assessment['error_messages'],
            ]);
        }

        $this->normalizeCategoryAncestors($product);

        $product->status = ProductStatus::Published;

        if ($product->published_at === null) {
            $product->published_at = now();
        }

        $product->editorial_ownership = EditorialOwnership::Human;
        $product->editorial_generation_version = null;
        $product->editorial_reviewed_at ??= now();
        $product->save();

        return [
            'warnings' => $assessment['warning_messages'],
        ];
    }

    private function normalizeCategoryAncestors(Product $product): void
    {
        $primary = $product->categories()
            ->wherePivot('is_primary', true)
            ->first();

        if ($primary === null) {
            return;
        }

        try {
            $normalized = $this->normalizeCategories->execute((int) $primary->id);
        } catch (InvalidArgumentException) {
            return;
        }

        $sync = [];

        foreach ($normalized->categoryIds as $categoryId) {
            $sync[$categoryId] = [
                'is_primary' => $categoryId === $normalized->primaryCategoryId,
            ];
        }

        $product->categories()->sync($sync);
    }
}
