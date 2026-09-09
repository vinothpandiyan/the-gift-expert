<?php

namespace App\Filament\Resources\Gifts\Concerns;

use App\Actions\CuratedCatalog\ApplyHumanProductTaxonomyClassificationAction;
use App\CuratedCatalog\ProductTaxonomyFormState;
use App\Filament\Resources\Gifts\Schemas\GiftClassificationSchema;
use App\Models\Product;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

trait AppliesGiftTaxonomyFormState
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillTaxonomyFormData(array $data): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Product) {
            return $data;
        }

        return array_merge($data, ProductTaxonomyFormState::fromProduct($record));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function forgetTaxonomyFormData(array $data): array
    {
        return Arr::except($data, GiftClassificationSchema::formKeys());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function persistTaxonomyFormData(Product $product, array $data): Product
    {
        $incoming = ProductTaxonomyFormState::fromForm($data);
        $current = ProductTaxonomyFormState::fromProduct($product);

        if (! ProductTaxonomyFormState::materiallyChanged($current, $incoming)) {
            return $product;
        }

        if ($incoming['primary_category_id'] === null
            && $incoming['relationship_ids'] === []
            && $incoming['recipient_type_ids'] === []
            && $incoming['occasion_ids'] === []
            && $incoming['interest_ids'] === []
            && $incoming['profession_ids'] === []
            && $incoming['gift_type_ids'] === []) {
            return $product;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'taxonomy' => ['You must be signed in to change taxonomy.'],
            ]);
        }

        try {
            return app(ApplyHumanProductTaxonomyClassificationAction::class)->execute($product, $incoming, $user);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Taxonomy was not saved')
                ->body(implode(' ', Arr::flatten($exception->errors())))
                ->danger()
                ->send();

            throw $exception;
        }
    }
}
