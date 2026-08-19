<?php

namespace App\Filament\Resources\Gifts;

use App\Actions\Product\EvaluateAndPersistProductAutomationReadinessAction;
use App\Actions\Product\PublishProductAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\ProductAutomationReadiness;
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
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
                Section::make('Automation readiness')
                    ->schema([
                        Placeholder::make('automation_readiness_summary')
                            ->label('Status')
                            ->content(function (?Product $record): string {
                                if ($record === null || $record->status !== ProductStatus::Draft) {
                                    return 'Automation readiness applies to draft gifts promoted from catalog candidates.';
                                }

                                $item = $record->latestPromotedSourcingItem;

                                if ($item === null) {
                                    return 'This gift was not promoted from a catalog candidate sourcing item.';
                                }

                                $readiness = $item->readiness?->value ?? 'not evaluated';
                                $codeCount = is_array($item->exception_codes) ? count($item->exception_codes) : 0;
                                $candidate = $item->candidate;
                                $candidateLabel = $candidate !== null
                                    ? $candidate->title
                                    : 'catalog candidate';

                                return sprintf(
                                    'Readiness: %s (%d exception codes). Sourced from %s.',
                                    $readiness,
                                    $codeCount,
                                    $candidateLabel,
                                );
                            }),
                    ])
                    ->visibleOn('edit')
                    ->collapsed(),
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('latestPromotedSourcingItem'))
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
                TextColumn::make('latestPromotedSourcingItem.readiness')
                    ->label('Readiness')
                    ->badge()
                    ->color(fn (?ProductAutomationReadiness $state, Product $record): string => $record->status === ProductStatus::Draft
                        ? self::readinessColor($state)
                        : 'gray')
                    ->formatStateUsing(fn (?ProductAutomationReadiness $state, Product $record): string => $record->status !== ProductStatus::Draft || $state === null
                        ? '—'
                        : str_replace('_', ' ', $state->name))
                    ->toggleable(),
                IconColumn::make('latestPromotedSourcingItem')
                    ->label('Promoted')
                    ->boolean()
                    ->getStateUsing(fn (Product $record): bool => $record->latestPromotedSourcingItem !== null)
                    ->toggleable(isToggledHiddenByDefault: true),
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
                SelectFilter::make('readiness')
                    ->label('Automation readiness')
                    ->options(collect(ProductAutomationReadiness::cases())->mapWithKeys(
                        fn (ProductAutomationReadiness $readiness): array => [
                            $readiness->value => str_replace('_', ' ', $readiness->name),
                        ],
                    ))
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === null || $value === '') {
                            return $query;
                        }

                        return $query
                            ->where('status', ProductStatus::Draft)
                            ->whereHas(
                                'latestPromotedSourcingItem',
                                fn (Builder $itemQuery): Builder => $itemQuery->where('readiness', $value),
                            );
                    }),
                Filter::make('missing_image')
                    ->label('Missing image')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', ProductStatus::Draft)
                        ->whereDoesntHave('images')),
                Filter::make('missing_primary_category')
                    ->label('Missing primary category')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', ProductStatus::Draft)
                        ->whereDoesntHave('categories', fn (Builder $categoryQuery): Builder => $categoryQuery->where('category_product.is_primary', true))),
                Filter::make('affiliate_issue')
                    ->label('Inactive or missing affiliate')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', ProductStatus::Draft)
                        ->whereDoesntHave('affiliateLinks', fn (Builder $linkQuery): Builder => $linkQuery->where('status', AffiliateLinkStatus::Active))),
                Filter::make('ready_for_publish')
                    ->label('Ready for publish')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', ProductStatus::Draft)
                        ->whereHas(
                            'latestPromotedSourcingItem',
                            fn (Builder $itemQuery): Builder => $itemQuery->where('readiness', ProductAutomationReadiness::Ready),
                        )),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('publishReady')
                        ->label('Publish ready gifts')
                        ->icon(Heroicon::OutlinedArrowUpTray)
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Publish ready draft gifts?')
                        ->modalDescription('Only draft gifts with automation readiness "ready" are published through the existing publish action.')
                        ->action(function (Collection $records): void {
                            $published = 0;
                            $failed = 0;
                            $errors = [];

                            foreach ($records as $product) {
                                /** @var Product $product */
                                if ($product->status !== ProductStatus::Draft) {
                                    $failed++;

                                    continue;
                                }

                                $item = $product->latestPromotedSourcingItem;

                                if ($item !== null) {
                                    $item = app(EvaluateAndPersistProductAutomationReadinessAction::class)->execute($item);
                                }

                                if ($item === null || $item->readiness !== ProductAutomationReadiness::Ready) {
                                    $failed++;

                                    continue;
                                }

                                try {
                                    app(PublishProductAction::class)->execute($product->fresh());
                                    $published++;
                                } catch (ValidationException $exception) {
                                    $failed++;
                                    $errors[] = $product->name.': '.implode(' ', Arr::flatten($exception->errors()));
                                }
                            }

                            $body = "Published {$published}, skipped or failed {$failed}.";

                            if ($errors !== []) {
                                $body .= ' '.implode(' ', array_slice($errors, 0, 3));
                            }

                            Notification::make()
                                ->title('Bulk publish finished')
                                ->body($body)
                                ->success()
                                ->send();
                        }),
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('latestPromotedSourcingItem');
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    private static function readinessColor(?ProductAutomationReadiness $readiness): string
    {
        return match ($readiness) {
            ProductAutomationReadiness::Ready => 'success',
            ProductAutomationReadiness::NeedsReview => 'warning',
            ProductAutomationReadiness::Blocked => 'danger',
            default => 'gray',
        };
    }
}
