<?php

namespace App\Filament\Resources\Gifts;

use App\Enums\ProductStatus;
use App\Filament\Resources\Gifts\Pages\CreateGift;
use App\Filament\Resources\Gifts\Pages\EditGift;
use App\Filament\Resources\Gifts\Pages\ListGifts;
use App\Filament\Resources\Gifts\RelationManagers\AffiliateLinksRelationManager;
use App\Filament\Resources\Gifts\RelationManagers\CategoriesRelationManager;
use App\Filament\Resources\Gifts\RelationManagers\GiftTypesRelationManager;
use App\Filament\Resources\Gifts\RelationManagers\ImagesRelationManager;
use App\Filament\Resources\Gifts\RelationManagers\InterestsRelationManager;
use App\Filament\Resources\Gifts\RelationManagers\OccasionsRelationManager;
use App\Filament\Resources\Gifts\RelationManagers\ProfessionsRelationManager;
use App\Filament\Resources\Gifts\RelationManagers\RecipientTypesRelationManager;
use App\Filament\Resources\Gifts\RelationManagers\RelationshipsRelationManager;
use App\Models\Product;
use App\Support\Terminology;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class GiftResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $slug = 'gifts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return Terminology::gift();
    }

    public static function getPluralModelLabel(): string
    {
        return Terminology::gifts();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if (filled($get('slug'))) {
                                    return;
                                }

                                $set('slug', Str::slug((string) $state));
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('brand')
                            ->maxLength(120),
                        TextInput::make('sku')
                            ->maxLength(80),
                        Textarea::make('short_description')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->rows(5)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Pricing & status')
                    ->schema([
                        Select::make('status')
                            ->options(collect(ProductStatus::cases())->mapWithKeys(
                                fn (ProductStatus $status): array => [$status->value => ucfirst($status->value)],
                            ))
                            ->default(ProductStatus::Draft->value)
                            ->disabled()
                            ->dehydrated(),
                        Toggle::make('is_featured')
                            ->default(false),
                        TextInput::make('price_amount')
                            ->numeric()
                            ->step(0.01),
                        TextInput::make('compare_at_amount')
                            ->numeric()
                            ->step(0.01),
                        TextInput::make('price_currency')
                            ->length(3)
                            ->default('INR')
                            ->required(),
                        TextInput::make('published_at')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn ($state): ?string => filled($state)
                                ? Carbon::parse($state)->toDateTimeString()
                                : null)
                            ->visibleOn('edit'),
                    ])
                    ->columns(2),
                Section::make('SEO')
                    ->schema([
                        TextInput::make('meta_title')
                            ->maxLength(255),
                        TextInput::make('canonical_url')
                            ->url()
                            ->maxLength(500),
                        Textarea::make('meta_description')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('price_amount')
                    ->money(fn (Product $record): string => $record->price_currency ?? 'INR')
                    ->sortable(),
                IconColumn::make('is_featured')
                    ->boolean(),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ProductStatus::cases())->mapWithKeys(
                        fn (ProductStatus $status): array => [$status->value => ucfirst($status->value)],
                    )),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            ImagesRelationManager::class,
            AffiliateLinksRelationManager::class,
            CategoriesRelationManager::class,
            OccasionsRelationManager::class,
            RelationshipsRelationManager::class,
            RecipientTypesRelationManager::class,
            InterestsRelationManager::class,
            ProfessionsRelationManager::class,
            GiftTypesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGifts::route('/'),
            'create' => CreateGift::route('/create'),
            'edit' => EditGift::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
