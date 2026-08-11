<?php

namespace App\Filament\Resources\Gifts\RelationManagers;

use App\Models\Category;
use App\Models\Product;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'categories';

    protected static ?string $title = 'Categories';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('is_primary')
                    ->label('Primary category')
                    ->default(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('full_path')
                    ->wrap(),
                IconColumn::make('is_primary')
                    ->boolean()
                    ->label('Primary'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn ($query) => $query->orderBy('full_path'))
                    ->recordTitle(fn (Category $record): string => $record->full_path)
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Toggle::make('is_primary')
                            ->label('Primary category')
                            ->default(false),
                    ])
                    ->after(function (array $data): void {
                        $preferredId = null;

                        if (! empty($data['is_primary']) && filled($data['recordId'] ?? null) && ! is_array($data['recordId'])) {
                            $preferredId = (int) $data['recordId'];
                        }

                        $this->enforceSinglePrimaryCategory($preferredId);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (Category $record): void {
                        $this->enforceSinglePrimaryCategory((int) $record->getKey());
                    }),
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }

    private function enforceSinglePrimaryCategory(?int $preferredCategoryId = null): void
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        $primaryIds = $product->categories()
            ->wherePivot('is_primary', true)
            ->orderByPivot('created_at', 'desc')
            ->orderByDesc('categories.id')
            ->pluck('categories.id');

        if ($primaryIds->count() <= 1) {
            return;
        }

        $keepId = ($preferredCategoryId !== null && $primaryIds->contains($preferredCategoryId))
            ? $preferredCategoryId
            : $primaryIds->first();

        $primaryIds
            ->reject(fn ($id): bool => (int) $id === (int) $keepId)
            ->each(function ($id) use ($product): void {
                $product->categories()->updateExistingPivot($id, [
                    'is_primary' => false,
                ]);
            });
    }
}
