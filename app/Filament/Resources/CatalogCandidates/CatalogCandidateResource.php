<?php

namespace App\Filament\Resources\CatalogCandidates;

use App\Actions\CatalogCandidate\FindCatalogCandidateProductOverlapAction;
use App\Enums\CatalogCandidatePriority;
use App\Enums\CatalogCandidateSourceType;
use App\Enums\CatalogCandidateStatus;
use App\Enums\ProductAutomationReadiness;
use App\Filament\Resources\CatalogCandidates\Pages\CreateCatalogCandidate;
use App\Filament\Resources\CatalogCandidates\Pages\EditCatalogCandidate;
use App\Filament\Resources\CatalogCandidates\Pages\ListCatalogCandidates;
use App\Filament\Resources\CatalogCandidates\RelationManagers\EvidenceRelationManager;
use App\Filament\Resources\Gifts\GiftResource;
use App\Models\CatalogCandidate;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CatalogCandidateResource extends Resource
{
    protected static ?string $model = CatalogCandidate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getModelLabel(): string
    {
        return 'Catalog Candidate';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Catalog Candidates';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Idea')
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true),
                        Callout::make('This title matches an existing gift')
                            ->description('A Product already uses this name. That is a warning only — saving still creates or updates a Catalog Candidate, not a Gift.')
                            ->warning()
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => self::titleOverlapsProduct($get('title'))),
                        Textarea::make('summary')
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                        Select::make('priority')
                            ->options(self::enumOptions(CatalogCandidatePriority::class))
                            ->default(CatalogCandidatePriority::Normal->value)
                            ->required(),
                        TextInput::make('status')
                            ->disabled()
                            ->dehydrated(false)
                            ->visibleOn('edit'),
                    ])
                    ->columns(2),
                Section::make('Source')
                    ->schema([
                        Select::make('source_type')
                            ->options(self::enumOptions(CatalogCandidateSourceType::class))
                            ->required(),
                        TextInput::make('source_name')
                            ->maxLength(120),
                        TextInput::make('source_url')
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        TextInput::make('external_reference')
                            ->maxLength(120),
                        DateTimePicker::make('discovered_at'),
                        Toggle::make('allow_similar_title')
                            ->label('Allow similar title')
                            ->helperText('Use only when this is intentionally a distinct candidate with the same normalized title. This does not bypass source URL or external-reference duplicates.')
                            ->default(false)
                            ->dehydrated()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Estimated price')
                    ->schema([
                        TextInput::make('estimated_price_amount')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('estimated_price_currency')
                            ->maxLength(3)
                            ->helperText('Optional ISO currency code. Leave blank when unknown.'),
                    ])
                    ->columns(2)
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'latestSourcingItem.merchant',
                'latestSourcingItem.product',
            ]))
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('latestSourcingItem.merchant.name')
                    ->label('Merchant')
                    ->toggleable(),
                TextColumn::make('latestSourcingItem.status')
                    ->label('Sourcing')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('latestSourcingItem.product.name')
                    ->label('Gift')
                    ->url(fn (CatalogCandidate $record): ?string => $record->latestSourcingItem?->product_id !== null
                        ? GiftResource::getUrl('edit', ['record' => $record->latestSourcingItem->product_id])
                        : null)
                    ->toggleable(),
                TextColumn::make('latestSourcingItem.readiness')
                    ->label('Readiness')
                    ->badge()
                    ->color(fn (?ProductAutomationReadiness $state): string => self::readinessColor($state))
                    ->formatStateUsing(fn (?ProductAutomationReadiness $state): string => $state === null
                        ? '—'
                        : str_replace('_', ' ', $state->name))
                    ->toggleable(),
                TextColumn::make('latestSourcingItem.exception_codes')
                    ->label('Exceptions')
                    ->formatStateUsing(fn ($state): int => is_array($state) ? count($state) : 0)
                    ->toggleable(),
                TextColumn::make('source_type')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('discovered_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('priority')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reviewed_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('readiness')
                    ->label('Automation readiness')
                    ->options([
                        ProductAutomationReadiness::Ready->value => 'Ready',
                        ProductAutomationReadiness::NeedsReview->value => 'Needs review',
                        ProductAutomationReadiness::Blocked->value => 'Blocked',
                        'unsourced' => 'Unsourced',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === null || $value === '') {
                            return $query;
                        }

                        if ($value === 'unsourced') {
                            return $query->whereDoesntHave('sourcingItems');
                        }

                        return $query->whereHas(
                            'latestSourcingItem',
                            fn (Builder $itemQuery): Builder => $itemQuery->where('readiness', $value),
                        );
                    }),
                TernaryFilter::make('has_product')
                    ->label('Has gift')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas(
                            'latestSourcingItem',
                            fn (Builder $itemQuery): Builder => $itemQuery->whereNotNull('product_id'),
                        ),
                        false: fn (Builder $query): Builder => $query->where(function (Builder $nested): void {
                            $nested->whereDoesntHave('sourcingItems')
                                ->orWhereHas(
                                    'latestSourcingItem',
                                    fn (Builder $itemQuery): Builder => $itemQuery->whereNull('product_id'),
                                );
                        }),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Filter::make('no_offer')
                    ->label('No offer')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'latestSourcingItem',
                        fn (Builder $itemQuery): Builder => $itemQuery->whereJsonContains('exception_codes', 'no_offer'),
                    )),
                Filter::make('affiliate_issue')
                    ->label('Affiliate issue')
                    ->query(fn (Builder $query): Builder => $query->whereHas('latestSourcingItem', function (Builder $itemQuery): void {
                        $itemQuery->where(function (Builder $nested): void {
                            foreach (['affiliate_manual', 'affiliate_not_ready', 'invalid_affiliate_url', 'no_active_affiliate_link'] as $code) {
                                $nested->orWhereJsonContains('exception_codes', $code);
                            }
                        });
                    })),
                Filter::make('image_issue')
                    ->label('Image issue')
                    ->query(fn (Builder $query): Builder => $query->whereHas('latestSourcingItem', function (Builder $itemQuery): void {
                        $itemQuery->where(function (Builder $nested): void {
                            foreach (['no_image', 'image_policy', 'image_acquisition_failed'] as $code) {
                                $nested->orWhereJsonContains('exception_codes', $code);
                            }
                        });
                    })),
                Filter::make('taxonomy_issue')
                    ->label('Taxonomy issue')
                    ->query(fn (Builder $query): Builder => $query->whereHas('latestSourcingItem', function (Builder $itemQuery): void {
                        $itemQuery->where(function (Builder $nested): void {
                            foreach (['missing_primary_category', 'taxonomy_ids_rejected', 'taxonomy_too_broad'] as $code) {
                                $nested->orWhereJsonContains('exception_codes', $code);
                            }
                        });
                    })),
                Filter::make('price_issue')
                    ->label('Price issue')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'latestSourcingItem',
                        fn (Builder $itemQuery): Builder => $itemQuery->whereJsonContains('exception_codes', 'missing_or_ambiguous_price'),
                    )),
                Filter::make('unsourced')
                    ->label('Unsourced')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('sourcingItems')),
                SelectFilter::make('status')
                    ->options(self::enumOptions(CatalogCandidateStatus::class)),
                SelectFilter::make('source_type')
                    ->options(self::enumOptions(CatalogCandidateSourceType::class)),
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
            ->defaultSort('discovered_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            EvidenceRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCatalogCandidates::route('/'),
            'create' => CreateCatalogCandidate::route('/create'),
            'edit' => EditCatalogCandidate::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['latestSourcingItem.merchant', 'latestSourcingItem.product']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return array<string, string>
     */
    public static function enumOptions(string $enum): array
    {
        return collect($enum::cases())->mapWithKeys(
            fn (BackedEnum $case): array => [
                $case->value => str_replace('_', ' ', $case->name),
            ],
        )->all();
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

    private static function titleOverlapsProduct(mixed $title): bool
    {
        if (! is_string($title) || trim($title) === '') {
            return false;
        }

        $candidate = new CatalogCandidate(['title' => $title]);

        return app(FindCatalogCandidateProductOverlapAction::class)
            ->execute($candidate)
            ->isNotEmpty();
    }
}
