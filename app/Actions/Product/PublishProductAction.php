<?php

namespace App\Actions\Product;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

class PublishProductAction
{
    public function __construct(
        private AssessProductPublicationRequirementsAction $assessPublicationRequirements,
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

        $product->status = ProductStatus::Published;

        if ($product->published_at === null) {
            $product->published_at = now();
        }

        $product->save();

        return [
            'warnings' => $assessment['warning_messages'],
        ];
    }
}
