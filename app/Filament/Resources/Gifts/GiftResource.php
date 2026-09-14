<?php

namespace App\Filament\Resources\Gifts;

use App\Actions\Product\PublishProductsAction;
use App\Enums\AffiliateLinkStatus;
use App\Enums\CurationAiConfidence;
use App\Enums\CurationIssueCode;
use App\Enums\CurationRecommendation;
use App\Enums\ProductAutomationReadiness;
use App\Enums\ProductStatus;
use App\Enums\TaxonomyClassificationStatus;
use App\Enums\TaxonomyClassificationWarningCode;
use App\Filament\Resources\Gifts\Pages\CreateGift;
use App\Filament\Resources\Gifts\Pages\EditGift;
use App\Filament\Resources\Gifts\Pages\ListGifts;
use App\Filament\Resources\Gifts\RelationManagers\AffiliateLinksRelationManager;
use App\Filament\Resources\Gifts\RelationManagers\ImagesRelationManager;
use App\Filament\Resources\Gifts\Schemas\GiftClassificationSchema;
use App\Filament\Resources\Gifts\Schemas\GiftCurationAuditSchema;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Relationship;
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
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
            ->columns(2)
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
                        Placeholder::make('editorial_ownership')
                            ->label('Editorial ownership')
                            ->content(fn (?Product $record): string => $record?->editorial_ownership?->getLabel() ?? 'Human-owned on creation')
                            ->helperText('Human-owned copy is protected from imports, classification, and AI editorial backfills.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpan(1),
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
                    ->columns(2)
                    ->columnSpan(1),
                GiftClassificationSchema::reviewSection(),
                GiftCurationAuditSchema::reviewSection(),
                GiftClassificationSchema::taxonomySection(),
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
                    ->columnSpanFull()
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
                        Placeholder::make('seo_ownership')
                            ->label('SEO ownership')
                            ->content(fn (?Product $record): string => $record?->seo_ownership?->getLabel() ?? 'Not generated')
                            ->helperText('Human-owned SEO is protected from automated generation and import workflows.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'latestCompletedCurationAudit',
                'latestPromotedSourcingItem',
                'images',
                'affiliateLinks.merchant',
            ]))
            ->recordTitleAttribute('name')
            ->columns([
                ImageColumn::make('primary_image')
                    ->label('Image')
                    ->getStateUsing(fn (Product $record): ?string => ($record->images->firstWhere('is_primary', true) ?? $record->images->first())?->url())
                    ->toggleable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('external_product_id')
                    ->label('External ID')
                    ->getStateUsing(fn (Product $record): ?string => ($record->affiliateLinks->firstWhere('is_primary', true) ?? $record->affiliateLinks->first())?->external_product_id)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->orWhereHas(
                            'affiliateLinks',
                            fn (Builder $linkQuery): Builder => $linkQuery->where('external_product_id', 'like', '%'.$search.'%'),
                        );
                    })
                    ->toggleable(),
                TextColumn::make('merchant_name')
                    ->label('Merchant')
                    ->getStateUsing(fn (Product $record): ?string => ($record->affiliateLinks->firstWhere('is_primary', true) ?? $record->affiliateLinks->first())?->merchant?->name)
                    ->toggleable(),
                TextColumn::make('slug')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('taxonomy_classification_status')
                    ->label('Classification')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('latestCompletedCurationAudit.gift_score')
                    ->label('Gift Score')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('latestCompletedCurationAudit.catalog_value_score')
                    ->label('Catalog Value')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('latestCompletedCurationAudit.ai_confidence')
                    ->label('AI confidence')
                    ->badge()
                    ->formatStateUsing(fn (?CurationAiConfidence $state): string => $state === null
                        ? '—'
                        : str($state->value)->headline()->toString())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('latestCompletedCurationAudit.recommendation')
                    ->label('Recommendation')
                    ->badge()
                    ->formatStateUsing(fn (?CurationRecommendation $state): string => $state === null
                        ? '—'
                        : str($state->value)->headline()->toString())
                    ->toggleable(),
                IconColumn::make('latestCompletedCurationAudit.requires_human_review')
                    ->label('Curation review')
                    ->boolean()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('taxonomy_review_reasons')
                    ->label('Review reasons')
                    ->badge()
                    ->separator(',')
                    ->formatStateUsing(function (mixed $state): mixed {
                        if (! is_string($state) || $state === '') {
                            return $state;
                        }

                        return TaxonomyClassificationWarningCode::labelFor($state);
                    })
                    ->toggleable(),
                TextColumn::make('primary_category_confidence')
                    ->label('Category confidence')
                    ->getStateUsing(function (Product $record): ?string {
                        $score = data_get($record->taxonomy_classification_proposal, 'confidence.primary_category');

                        if (! is_numeric($score)) {
                            return null;
                        }

                        return ((string) (int) round(((float) $score) * 100)).'%';
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

                        return $query->orderByRaw(
                            'CAST(JSON_UNQUOTE(JSON_EXTRACT(taxonomy_classification_proposal, \'$.confidence.primary_category\')) AS DECIMAL(6,4)) '.$direction,
                        );
                    })
                    ->toggleable(),
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
                SelectFilter::make('taxonomy_classification_status')
                    ->label('Classification')
                    ->options(collect(TaxonomyClassificationStatus::cases())->mapWithKeys(
                        fn (TaxonomyClassificationStatus $status): array => [
                            $status->value => $status->getLabel() ?? $status->value,
                        ],
                    )),
                SelectFilter::make('merchant_id')
                    ->label('Merchant')
                    ->options(fn (): array => Merchant::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === null || $value === '') {
                            return $query;
                        }

                        return $query->whereHas(
                            'affiliateLinks',
                            fn (Builder $linkQuery): Builder => $linkQuery->where('merchant_id', $value),
                        );
                    }),
                SelectFilter::make('taxonomy_review_reason')
                    ->label('Review reason')
                    ->options(TaxonomyClassificationWarningCode::filterOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! is_string($value) || $value === '') {
                            return $query;
                        }

                        return $query->whereJsonContains('taxonomy_review_reasons', $value);
                    }),
                SelectFilter::make('source_relationship_id')
                    ->label('Source relationship hint')
                    ->options(fn (): array => Relationship::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === null || $value === '') {
                            return $query;
                        }

                        return $query->whereHas(
                            'affiliateLinks.catalogProductSources.sourceList',
                            fn (Builder $listQuery): Builder => $listQuery->where('relationship_id', $value),
                        );
                    }),
                Filter::make('taxonomy_proposal_pending')
                    ->label('Pending AI proposal')
                    ->query(fn (Builder $query): Builder => $query->where('taxonomy_proposal_pending', true)),
                Filter::make('curation_requires_human_review')
                    ->label('Requires human review')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'latestCompletedCurationAudit',
                        fn (Builder $auditQuery): Builder => $auditQuery->where('requires_human_review', true),
                    )),
                Filter::make('curation_low_gift_score')
                    ->label('Low Gift Score')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'latestCompletedCurationAudit',
                        fn (Builder $auditQuery): Builder => $auditQuery->where(
                            'gift_score',
                            '<',
                            (int) config('catalog_curation.thresholds.human_review_gift_score', 65),
                        ),
                    )),
                Filter::make('curation_low_catalog_value')
                    ->label('Low Catalog Value')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'latestCompletedCurationAudit',
                        fn (Builder $auditQuery): Builder => $auditQuery->where(
                            'catalog_value_score',
                            '<',
                            (int) config('catalog_curation.thresholds.human_review_catalog_value_score', 50),
                        ),
                    )),
                Filter::make('curation_low_confidence')
                    ->label('Low AI confidence')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'latestCompletedCurationAudit',
                        fn (Builder $auditQuery): Builder => $auditQuery->where('ai_confidence', CurationAiConfidence::Low),
                    )),
                SelectFilter::make('curation_issue_code')
                    ->label('Curation issue')
                    ->options(collect(CurationIssueCode::cases())->mapWithKeys(
                        fn (CurationIssueCode $code): array => [
                            $code->value => str($code->value)->replace('_', ' ')->headline()->toString(),
                        ],
                    ))
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! is_string($value) || $value === '') {
                            return $query;
                        }

                        return $query->whereHas(
                            'latestCompletedCurationAudit',
                            function (Builder $auditQuery) use ($value): Builder {
                                if ($auditQuery->getConnection()->getDriverName() === 'sqlite') {
                                    return $auditQuery->whereRaw(
                                        "EXISTS (SELECT 1 FROM json_each(product_curation_audits.issues) WHERE json_extract(json_each.value, '$.code') = ?)",
                                        [$value],
                                    );
                                }

                                return $auditQuery->whereJsonContains('issues', ['code' => $value]);
                            },
                        );
                    }),
                SelectFilter::make('curation_recommendation')
                    ->label('Curation recommendation')
                    ->options(collect(CurationRecommendation::cases())->mapWithKeys(
                        fn (CurationRecommendation $recommendation): array => [
                            $recommendation->value => str($recommendation->value)->replace('_', ' ')->headline()->toString(),
                        ],
                    ))
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! is_string($value) || $value === '') {
                            return $query;
                        }

                        return $query->whereHas(
                            'latestCompletedCurationAudit',
                            fn (Builder $auditQuery): Builder => $auditQuery->where('recommendation', $value),
                        );
                    }),
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
                        ->whereIn('taxonomy_classification_status', [
                            TaxonomyClassificationStatus::AiAccepted,
                            TaxonomyClassificationStatus::HumanApproved,
                            TaxonomyClassificationStatus::HumanOverridden,
                        ])
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
                        ->modalHeading('Publish selected draft gifts?')
                        ->modalDescription('Each selected draft is validated and published through the same publication action used by the individual Publish button.')
                        ->action(function (Collection $records): void {
                            $attempts = app(PublishProductsAction::class)->execute($records, 'filament:gifts.bulk-publish');
                            $tally = PublishProductsAction::tally($attempts);
                            $published = $tally['published'];
                            $skipped = $tally['skipped'];
                            $failed = $tally['failed'];
                            $details = $attempts
                                ->reject(fn ($attempt): bool => $attempt->result->value === 'published')
                                ->map(fn ($attempt): string => "#{$attempt->productId} {$attempt->result->value}: {$attempt->reason}")
                                ->take(5)
                                ->values()
                                ->all();

                            $body = "Published: {$published}. Skipped: {$skipped}. Failed: {$failed}.";

                            if ($details !== []) {
                                $body .= ' '.implode(' ', $details);

                                if ($skipped + $failed > 5) {
                                    $body .= ' '.(($skipped + $failed) - 5).' additional result(s) omitted; check the affected gifts individually.';
                                }
                            }

                            $notification = Notification::make()
                                ->title('Bulk publish finished')
                                ->body($body);

                            match (true) {
                                $failed > 0 => $notification->danger(),
                                $skipped > 0 => $notification->warning(),
                                default => $notification->success(),
                            };

                            $notification->send();
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
            ->with([
                'latestCompletedCurationAudit',
                'latestPromotedSourcingItem',
                'images',
                'affiliateLinks.merchant',
            ]);
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
