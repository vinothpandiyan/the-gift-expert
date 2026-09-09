<?php

namespace App\Filament\Resources\CatalogSourceLists;

use App\Filament\Resources\CatalogSourceLists\Pages\ListCatalogSourceLists;
use App\Models\CatalogSourceList;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CatalogSourceListResource extends Resource
{
    protected static ?string $model = CatalogSourceList::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Source Lists';

    protected static ?string $modelLabel = 'source list';

    protected static ?string $pluralModelLabel = 'source lists';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['merchant', 'relationship'])
                ->withCount('productSources')
                ->withMax('productSources', 'last_seen_at'))
            ->columns([
                TextColumn::make('merchant.name')
                    ->label('Merchant')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('external_list_id')
                    ->label('External list ID')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('kind')
                    ->badge()
                    ->sortable(),
                TextColumn::make('relationship.name')
                    ->label('Relationship hint')
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('product_sources_count')
                    ->label('Products')
                    ->sortable(),
                TextColumn::make('product_sources_max_last_seen_at')
                    ->label('Last seen')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('merchant_id')
                    ->relationship('merchant', 'name')
                    ->label('Merchant'),
                SelectFilter::make('kind'),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->defaultSort('name')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCatalogSourceLists::route('/'),
        ];
    }
}
