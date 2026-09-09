<?php

namespace App\Filament\Resources\Gifts\Schemas;

use App\Actions\CuratedCatalog\LoadGiftTaxonomySelectOptionsAction;
use App\Actions\Product\NormalizeProductCategoryAssignmentsAction;
use App\Models\Category;
use App\Models\Product;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use InvalidArgumentException;

class GiftClassificationSchema
{
    public static function reviewSection(): Section
    {
        return Section::make('Classification review')
            ->schema([
                View::make('filament.gifts.partials.classification-review')
                    ->viewData(fn (?Product $record): array => [
                        'reviewProduct' => $record,
                    ])
                    ->columnSpanFull(),
            ])
            ->extraAttributes([
                'class' => 'min-w-0',
                'data-classification-review-section' => '',
            ])
            ->columns(1)
            ->columnSpanFull()
            ->visibleOn('edit')
            ->collapsible();
    }

    public static function taxonomySection(): Section
    {
        return Section::make('Taxonomy edit')
            ->description('Select the most specific primary category. Parent categories are assigned automatically. Only active merchandising values are shown.')
            ->schema([
                self::taxonomySelect('primary_category_id', 'Primary Category', 'categories', multiple: false)
                    ->live()
                    ->columnSpanFull(),
                Placeholder::make('derived_category_ancestors')
                    ->label('Category ancestors')
                    ->content(function (Get $get): string {
                        $id = $get('primary_category_id');

                        if (blank($id)) {
                            return 'Select one most-specific category. Ancestors are assigned automatically.';
                        }

                        try {
                            $normalized = app(NormalizeProductCategoryAssignmentsAction::class)->execute((int) $id);
                        } catch (InvalidArgumentException) {
                            return 'The selected category cannot be used as a merchandising primary.';
                        }

                        $ancestorIds = array_values(array_filter(
                            $normalized->categoryIds,
                            fn (int $categoryId): bool => $categoryId !== $normalized->primaryCategoryId,
                        ));

                        if ($ancestorIds === []) {
                            return 'No parent categories.';
                        }

                        return Category::query()
                            ->whereIn('id', $ancestorIds)
                            ->orderBy('name')
                            ->pluck('name')
                            ->filter()
                            ->implode(' → ');
                    })
                    ->columnSpanFull(),
                self::taxonomySelect('relationship_ids', 'Relationships', 'relationships'),
                self::taxonomySelect('recipient_type_ids', 'Recipient types', 'recipient_types'),
                self::taxonomySelect('occasion_ids', 'Occasions', 'occasions'),
                self::taxonomySelect('interest_ids', 'Interests', 'interests'),
                self::taxonomySelect('profession_ids', 'Professions', 'professions'),
                self::taxonomySelect('gift_type_ids', 'Gift types', 'gift_types'),
            ])
            ->extraAttributes(['class' => 'min-w-0'])
            ->columns([
                'default' => 1,
                'md' => 2,
            ])
            ->columnSpanFull();
    }

    /**
     * @return list<string>
     */
    public static function formKeys(): array
    {
        return [
            'primary_category_id',
            'relationship_ids',
            'recipient_type_ids',
            'occasion_ids',
            'interest_ids',
            'profession_ids',
            'gift_type_ids',
        ];
    }

    private static function taxonomySelect(string $name, string $label, string $optionKey, bool $multiple = true): Select
    {
        $select = Select::make($name)
            ->label($label)
            ->options(fn (): array => app(LoadGiftTaxonomySelectOptionsAction::class)->execute()[$optionKey])
            ->searchable()
            ->preload()
            ->dehydrated()
            ->extraFieldWrapperAttributes(['class' => 'min-w-0']);

        return $multiple ? $select->multiple() : $select;
    }
}
