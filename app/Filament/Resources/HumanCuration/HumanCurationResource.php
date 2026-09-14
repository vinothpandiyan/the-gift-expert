<?php

namespace App\Filament\Resources\HumanCuration;

use App\Actions\CatalogCuration\CaptureProductTaxonomySnapshotAction;
use App\Actions\CatalogCuration\PreviewHumanTaxonomyProposalAction;
use App\Actions\CatalogCuration\QueryHumanCurationQueueAction;
use App\Actions\CatalogCuration\ResolveCurationReviewProgressAction;
use App\Actions\CatalogCuration\ResolveP3QualitySubgroupAction;
use App\Actions\CatalogCuration\ResolveProductCurationPriorityAction;
use App\Actions\CuratedCatalog\LoadGiftTaxonomySelectOptionsAction;
use App\CatalogCuration\HumanCurationQueueCriteria;
use App\CatalogCuration\HumanTaxonomyProposal;
use App\CatalogCuration\ProductTaxonomySnapshot;
use App\Enums\CatalogRole;
use App\Enums\CurationAiConfidence;
use App\Enums\CurationIssueSeverity;
use App\Enums\CurationRecommendation;
use App\Enums\GiftIntent;
use App\Enums\P3QualitySubgroup;
use App\Enums\ProductCurationDecision;
use App\Enums\ProductCurationDecisionReasonCode;
use App\Enums\ProductCurationPriority;
use App\Enums\ProductCurationRemediationStatus;
use App\Enums\ProductStatus;
use App\Filament\Resources\Gifts\GiftResource;
use App\Filament\Resources\HumanCuration\Pages\ListHumanCuration;
use App\Filament\Resources\HumanCuration\Pages\ReviewHumanCuration;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\Relationship;
use App\Support\Terminology;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class HumanCurationResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $slug = 'human-curation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Human Curation Workbench';

    public static function getModelLabel(): string
    {
        return 'Human curation review';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Human Curation Workbench';
    }

    public static function canViewAny(): bool
    {
        return GiftResource::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return GiftResource::canView($record);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $remaining = app(ResolveCurationReviewProgressAction::class)->execute()->remaining;

        return $remaining > 0 ? (string) $remaining : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Human curation decision')
                    ->description(ProductCurationDecision::defaultOperatorConsequence())
                    ->schema([
                        Select::make('decision')
                            ->label('Decision')
                            ->options(collect(ProductCurationDecision::cases())->mapWithKeys(
                                fn (ProductCurationDecision $decision): array => [$decision->value => $decision->getLabel()],
                            ))
                            ->required()
                            ->native(false)
                            ->live(),
                        CheckboxList::make('reason_codes')
                            ->label('Reason codes')
                            ->options(fn () => ProductCurationDecisionReasonCode::options())
                            ->columns(2)
                            ->columnSpanFull()
                            ->required(fn (Get $get): bool => in_array($get('decision'), [
                                ProductCurationDecision::Keep->value,
                                ProductCurationDecision::Feature->value,
                                ProductCurationDecision::KeepNiche->value,
                                ProductCurationDecision::Deactivate->value,
                                ProductCurationDecision::RemoveCandidate->value,
                            ], true)),
                        Textarea::make('reason_notes')
                            ->label('Notes')
                            ->rows(4)
                            ->columnSpanFull()
                            ->required(fn (Get $get): bool => in_array($get('decision'), [
                                ProductCurationDecision::Defer->value,
                                ProductCurationDecision::Deactivate->value,
                            ], true)
                                || ($get('decision') === ProductCurationDecision::Reclassify->value
                                    && blank($get('reason_codes')))),
                        Select::make('catalog_role')
                            ->label('Merchandising role')
                            ->options(collect(CatalogRole::humanMerchandisingCases())->mapWithKeys(
                                fn (CatalogRole $role): array => [
                                    $role->value => str($role->value)->replace('_', ' ')->headline()->toString(),
                                ],
                            ))
                            ->visible(fn (Get $get): bool => filled($get('decision'))
                                && ProductCurationDecision::tryFrom((string) $get('decision'))?->allowsCatalogRole())
                            ->native(false),
                        Hidden::make('expected_current_decision_id'),
                        Toggle::make('confirm_supersede')
                            ->label('Confirm superseding the newer current decision')
                            ->helperText('Required only when another reviewer saved a newer decision while this page was open.')
                            ->visible(fn (?Product $record): bool => $record?->currentCurationDecision !== null),
                        Placeholder::make('decision_safety')
                            ->label('Decision preview')
                            ->content(fn (Get $get): string => ProductCurationDecision::tryFrom((string) $get('decision'))
                                ?->operatorConsequence()
                                ?? ProductCurationDecision::defaultOperatorConsequence())
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Human taxonomy proposal')
                    ->description('Structured delta only. AI suggestions stay advisory until you explicitly add or remove a value.')
                    ->visible(fn (Get $get): bool => $get('decision') === ProductCurationDecision::Reclassify->value)
                    ->schema([
                        Select::make('taxonomy_primary_category_id')
                            ->label('Primary category')
                            ->options(fn (): array => app(LoadGiftTaxonomySelectOptionsAction::class)->execute()['categories'])
                            ->searchable()
                            ->preload()
                            ->live()
                            ->native(false)
                            ->columnSpanFull(),
                        ...self::taxonomyDeltaFields(),
                        Placeholder::make('taxonomy_delta_preview')
                            ->label('Proposed delta')
                            ->content(fn (Get $get, ?Product $record): string => self::deltaPreviewContent($record, [
                                'taxonomy_primary_category_id' => $get('taxonomy_primary_category_id'),
                                'taxonomy_relationships_add' => $get('taxonomy_relationships_add'),
                                'taxonomy_relationships_remove' => $get('taxonomy_relationships_remove'),
                                'taxonomy_occasions_add' => $get('taxonomy_occasions_add'),
                                'taxonomy_occasions_remove' => $get('taxonomy_occasions_remove'),
                                'taxonomy_interests_add' => $get('taxonomy_interests_add'),
                                'taxonomy_interests_remove' => $get('taxonomy_interests_remove'),
                                'taxonomy_gift_types_add' => $get('taxonomy_gift_types_add'),
                                'taxonomy_gift_types_remove' => $get('taxonomy_gift_types_remove'),
                                'taxonomy_recipient_types_add' => $get('taxonomy_recipient_types_add'),
                                'taxonomy_recipient_types_remove' => $get('taxonomy_recipient_types_remove'),
                                'taxonomy_professions_add' => $get('taxonomy_professions_add'),
                                'taxonomy_professions_remove' => $get('taxonomy_professions_remove'),
                            ]))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                return $query->with([
                    'images',
                    'affiliateLinks.merchant',
                    'currentCurationDecision.decidedBy',
                    'curationAudits' => fn ($auditQuery) => $auditQuery->where(
                        'run_id',
                        app(QueryHumanCurationQueueAction::class)->acceptedRun()?->id,
                    ),
                ]);
            })
            ->columns([
                ImageColumn::make('primary_image')
                    ->label('Image')
                    ->getStateUsing(fn (Product $record): ?string => ($record->images->firstWhere('is_primary', true) ?? $record->images->first())?->url()),
                TextColumn::make('name')
                    ->label(Terminology::gift())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('priority')
                    ->label('Priority')
                    ->badge()
                    ->getStateUsing(fn (Product $record): ?ProductCurationPriority => self::priority($record))
                    ->sortable(query: fn (Builder $query): Builder => $query),
                TextColumn::make('quality_subgroup')
                    ->label('P3 cohort')
                    ->badge()
                    ->getStateUsing(fn (Product $record): ?P3QualitySubgroup => self::qualitySubgroup($record))
                    ->toggleable(),
                TextColumn::make('gift_score')
                    ->label('Gift Score')
                    ->getStateUsing(fn (Product $record): ?int => self::baselineAudit($record)?->gift_score)
                    ->sortable(query: fn (Builder $query): Builder => $query),
                TextColumn::make('catalog_value_score')
                    ->label('Catalog Value')
                    ->getStateUsing(fn (Product $record): ?int => self::baselineAudit($record)?->catalog_value_score)
                    ->sortable(query: fn (Builder $query): Builder => $query),
                TextColumn::make('recommendation')
                    ->label('Audit recommendation')
                    ->badge()
                    ->getStateUsing(fn (Product $record): ?CurationRecommendation => self::baselineAudit($record)?->recommendation)
                    ->formatStateUsing(fn (?CurationRecommendation $state): string => $state?->value
                        ? str($state->value)->replace('_', ' ')->headline()->toString()
                        : '—'),
                TextColumn::make('human_decision')
                    ->label('Human decision')
                    ->badge()
                    ->getStateUsing(fn (Product $record): ?ProductCurationDecision => $record->currentCurationDecision?->decision),
                TextColumn::make('concept_label')
                    ->label('Concept')
                    ->getStateUsing(fn (Product $record): ?string => self::baselineAudit($record)?->concept_label)
                    ->toggleable(),
                TextColumn::make('concept_peer_count')
                    ->label('Concept peers')
                    ->getStateUsing(fn (Product $record): int => self::peerCount($record))
                    ->sortable(query: fn (Builder $query): Builder => $query),
                TextColumn::make('taxonomy_severity')
                    ->label('Taxonomy severity')
                    ->badge()
                    ->getStateUsing(function (Product $record): ?string {
                        $audit = self::baselineAudit($record);

                        return $audit instanceof ProductCurationAudit
                            ? app(ResolveProductCurationPriorityAction::class)->taxonomySeverity($audit)
                            : null;
                    })
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? str($state)->headline()->toString()
                        : '—')
                    ->toggleable(),
                TextColumn::make('evidence_status')
                    ->label('Evidence')
                    ->badge()
                    ->getStateUsing(function (Product $record): string {
                        $audit = self::baselineAudit($record);

                        if (! $audit instanceof ProductCurationAudit) {
                            return 'Unknown';
                        }

                        return app(ResolveProductCurationPriorityAction::class)->hasEvidenceIssue($audit)
                            ? 'Issue'
                            : 'OK';
                    })
                    ->toggleable(),
                TextColumn::make('ai_confidence')
                    ->label('AI confidence')
                    ->badge()
                    ->getStateUsing(fn (Product $record): ?CurationAiConfidence => self::baselineAudit($record)?->ai_confidence)
                    ->formatStateUsing(fn (?CurationAiConfidence $state): string => $state
                        ? str($state->value)->headline()->toString()
                        : '—')
                    ->toggleable(),
                TextColumn::make('last_reviewed')
                    ->label('Last reviewed')
                    ->getStateUsing(fn (Product $record): ?string => $record->currentCurationDecision?->decided_at?->toDateTimeString())
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('sort')
                    ->label('Sort')
                    ->options([
                        'priority' => 'Priority, then Catalog Value, then Gift Score',
                        'quality_cohort' => 'P3 quality cohort, then Gift Score',
                        'gift_score_asc' => 'Gift Score ascending',
                        'catalog_value_asc' => 'Catalog Value ascending',
                        'catalog_value_desc' => 'Catalog Value descending',
                        'concept_peer_count_desc' => 'Concept peer count descending',
                        'product_id' => 'Product ID',
                    ])
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('priority')
                    ->options(collect(ProductCurationPriority::cases())->mapWithKeys(
                        fn (ProductCurationPriority $priority): array => [$priority->value => $priority->getLabel()],
                    ))
                    ->query(fn (Builder $query): Builder => $query),
                TernaryFilter::make('requires_human_review')
                    ->label('Human review required')
                    ->queries(
                        true: fn (Builder $query): Builder => $query,
                        false: fn (Builder $query): Builder => $query,
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('human_decision')
                    ->label('Human decision')
                    ->options(collect(ProductCurationDecision::cases())->mapWithKeys(
                        fn (ProductCurationDecision $decision): array => [$decision->value => $decision->getLabel()],
                    ))
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('recommendation')
                    ->label('Audit recommendation')
                    ->options(collect(CurationRecommendation::cases())->mapWithKeys(
                        fn (CurationRecommendation $recommendation): array => [
                            $recommendation->value => str($recommendation->value)->replace('_', ' ')->headline()->toString(),
                        ],
                    ))
                    ->query(fn (Builder $query): Builder => $query),
                Filter::make('gift_score')
                    ->schema([
                        TextInput::make('gift_score_min')->numeric()->label('Gift Score min'),
                        TextInput::make('gift_score_max')->numeric()->label('Gift Score max'),
                    ])
                    ->query(fn (Builder $query): Builder => $query),
                Filter::make('catalog_value')
                    ->schema([
                        TextInput::make('catalog_value_min')->numeric()->label('Catalog Value min'),
                        TextInput::make('catalog_value_max')->numeric()->label('Catalog Value max'),
                    ])
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('concept')
                    ->label('Concept')
                    ->options(fn (): array => self::conceptFilterOptions())
                    ->searchable()
                    ->query(fn (Builder $query): Builder => $query),
                TernaryFilter::make('has_concept_peers')
                    ->label('Has concept peers')
                    ->queries(
                        true: fn (Builder $query): Builder => $query,
                        false: fn (Builder $query): Builder => $query,
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('relationship_id')
                    ->label('Relationship')
                    ->options(fn (): array => self::activeTaxonomyOptions(Relationship::class))
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('occasion_id')
                    ->label('Occasion')
                    ->options(fn (): array => self::activeTaxonomyOptions(Occasion::class))
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('interest_id')
                    ->label('Interest')
                    ->options(fn (): array => self::activeTaxonomyOptions(Interest::class))
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('gift_type_id')
                    ->label('Gift type')
                    ->options(fn (): array => self::activeTaxonomyOptions(GiftType::class))
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('budget_band')
                    ->label('Budget band')
                    ->options(collect((array) config('catalog_curation.price_bands', []))->mapWithKeys(
                        fn (array $band): array => [$band['slug'] => str($band['slug'])->replace('-', ' ')->headline()->toString()],
                    )->all())
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('gift_intent')
                    ->label('GiftIntent')
                    ->options(collect(GiftIntent::cases())->mapWithKeys(
                        fn (GiftIntent $intent): array => [
                            $intent->value => str($intent->value)->replace('_', ' ')->headline()->toString(),
                        ],
                    ))
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('taxonomy_severity')
                    ->label('Taxonomy severity')
                    ->options(collect(CurationIssueSeverity::cases())->mapWithKeys(
                        fn (CurationIssueSeverity $severity): array => [
                            $severity->value => str($severity->value)->headline()->toString(),
                        ],
                    ))
                    ->query(fn (Builder $query): Builder => $query),
                TernaryFilter::make('evidence_issue')
                    ->label('Evidence issue')
                    ->queries(
                        true: fn (Builder $query): Builder => $query,
                        false: fn (Builder $query): Builder => $query,
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('ai_confidence')
                    ->label('AI confidence')
                    ->options(collect(CurationAiConfidence::cases())->mapWithKeys(
                        fn (CurationAiConfidence $confidence): array => [
                            $confidence->value => str($confidence->value)->headline()->toString(),
                        ],
                    ))
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('status')
                    ->label('Published / Draft')
                    ->options(collect(ProductStatus::cases())->mapWithKeys(
                        fn (ProductStatus $status): array => [$status->value => ucfirst($status->value)],
                    ))
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('quality_subgroup')
                    ->label('P3 cohort')
                    ->options(collect(P3QualitySubgroup::casesInReviewOrder())->mapWithKeys(
                        fn (P3QualitySubgroup $subgroup): array => [$subgroup->value => $subgroup->getLabel()],
                    ))
                    ->query(fn (Builder $query): Builder => $query),
            ])
            ->recordUrl(fn (Product $record): string => static::getUrl('review', [
                'record' => $record,
                'queueTab' => request('tab', 'needs_review'),
            ]))
            ->defaultKeySort(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHumanCuration::route('/'),
            'review' => ReviewHumanCuration::route('/{record}/review'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'images',
            'affiliateLinks.merchant',
            'currentCurationDecision.decidedBy',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $tableFilters
     */
    public static function criteriaFromTableState(
        ?array $tableFilters,
        ?string $activeTab,
        ?string $sortColumn,
        ?string $sortDirection,
    ): HumanCurationQueueCriteria {
        $filters = $tableFilters ?? [];
        $flat = [
            'priority' => data_get($filters, 'priority.value'),
            'requires_human_review' => data_get($filters, 'requires_human_review.value'),
            'human_decision' => data_get($filters, 'human_decision.value'),
            'recommendation' => data_get($filters, 'recommendation.value'),
            'gift_score_min' => data_get($filters, 'gift_score.gift_score_min'),
            'gift_score_max' => data_get($filters, 'gift_score.gift_score_max'),
            'catalog_value_min' => data_get($filters, 'catalog_value.catalog_value_min'),
            'catalog_value_max' => data_get($filters, 'catalog_value.catalog_value_max'),
            'concept' => data_get($filters, 'concept.value'),
            'has_concept_peers' => data_get($filters, 'has_concept_peers.value'),
            'relationship_id' => data_get($filters, 'relationship_id.value'),
            'occasion_id' => data_get($filters, 'occasion_id.value'),
            'interest_id' => data_get($filters, 'interest_id.value'),
            'gift_type_id' => data_get($filters, 'gift_type_id.value'),
            'budget_band' => data_get($filters, 'budget_band.value'),
            'gift_intent' => data_get($filters, 'gift_intent.value'),
            'taxonomy_severity' => data_get($filters, 'taxonomy_severity.value'),
            'evidence_issue' => data_get($filters, 'evidence_issue.value'),
            'ai_confidence' => data_get($filters, 'ai_confidence.value'),
            'status' => data_get($filters, 'status.value'),
            'quality_subgroup' => data_get($filters, 'quality_subgroup.value'),
        ];

        $explicitSort = data_get($filters, 'sort.value');
        $sort = is_string($explicitSort) && $explicitSort !== ''
            ? $explicitSort
            : match ($sortColumn) {
                'gift_score' => 'gift_score_asc',
                'catalog_value_score' => $sortDirection === 'desc' ? 'catalog_value_desc' : 'catalog_value_asc',
                'concept_peer_count' => 'concept_peer_count_desc',
                'quality_subgroup' => 'quality_cohort',
                'name', 'id' => 'product_id',
                default => 'priority',
            };

        return HumanCurationQueueCriteria::fromFilters($flat, $activeTab ?: 'needs_review', $sort);
    }

    public static function baselineAudit(Product $record): ?ProductCurationAudit
    {
        if ($record->relationLoaded('curationAudits')) {
            $audit = $record->curationAudits->first();

            return $audit instanceof ProductCurationAudit ? $audit : null;
        }

        $runId = app(QueryHumanCurationQueueAction::class)->acceptedRun()?->id;

        if ($runId === null) {
            return $record->latestCompletedCurationAudit;
        }

        return $record->curationAudits()
            ->where('run_id', $runId)
            ->first();
    }

    public static function priority(Product $record): ?ProductCurationPriority
    {
        $audit = self::baselineAudit($record);

        return $audit instanceof ProductCurationAudit
            ? app(ResolveProductCurationPriorityAction::class)->execute($audit)
            : null;
    }

    public static function peerCount(Product $record): int
    {
        $audit = self::baselineAudit($record);

        return $audit instanceof ProductCurationAudit
            ? app(ResolveProductCurationPriorityAction::class)->conceptPeerCount($audit)
            : 0;
    }

    public static function qualitySubgroup(Product $record): ?P3QualitySubgroup
    {
        $audit = self::baselineAudit($record);

        return $audit instanceof ProductCurationAudit
            ? app(ResolveP3QualitySubgroupAction::class)->execute($audit)
            : null;
    }

    /**
     * @return array<string, string>
     */
    public static function conceptFilterOptions(): array
    {
        return once(function (): array {
            $runId = app(QueryHumanCurationQueueAction::class)->acceptedRun()?->id;

            if ($runId === null) {
                return [];
            }

            return ProductCurationAudit::query()
                ->where('run_id', $runId)
                ->whereNotNull('concept_key')
                ->orderBy('concept_label')
                ->pluck('concept_label', 'concept_key')
                ->all();
        });
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<int, string>
     */
    public static function activeTaxonomyOptions(string $model): array
    {
        return $model::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return list<Field>
     */
    public static function taxonomyDeltaFields(): array
    {
        $fields = [];

        foreach ([
            'relationships' => 'Relationships',
            'occasions' => 'Occasions',
            'interests' => 'Interests',
            'gift_types' => 'Gift types',
            'recipient_types' => 'Recipient types',
            'professions' => 'Professions',
        ] as $dimension => $label) {
            $fields[] = CheckboxList::make('taxonomy_'.$dimension.'_remove')
                ->label('Remove '.$label)
                ->options(fn (?Product $record): array => self::currentTaxonomyOptions($record, $dimension))
                ->live()
                ->columns(2);
            $fields[] = Select::make('taxonomy_'.$dimension.'_add')
                ->label('Add '.$label)
                ->options(fn (?Product $record): array => self::addableTaxonomyOptions($record, $dimension))
                ->multiple()
                ->searchable()
                ->preload()
                ->live()
                ->native(false);
        }

        return $fields;
    }

    public static function currentSnapshot(?Product $record): ?ProductTaxonomySnapshot
    {
        return $record instanceof Product
            ? app(CaptureProductTaxonomySnapshotAction::class)->execute($record)
            : null;
    }

    /**
     * @return array<int, string>
     */
    public static function currentTaxonomyOptions(?Product $record, string $dimension): array
    {
        $snapshot = self::currentSnapshot($record);

        if (! $snapshot instanceof ProductTaxonomySnapshot) {
            return [];
        }

        return collect($snapshot->idsFor($dimension))
            ->mapWithKeys(fn (int $id): array => [$id => $snapshot->name($dimension, $id)])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function addableTaxonomyOptions(?Product $record, string $dimension): array
    {
        $options = app(LoadGiftTaxonomySelectOptionsAction::class)->execute()[$dimension] ?? [];
        $current = array_keys(self::currentTaxonomyOptions($record, $dimension));

        return collect($options)
            ->reject(fn (string $name, int $id): bool => in_array($id, $current, true))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $form
     */
    public static function deltaPreviewContent(?Product $record, array $form): string
    {
        $snapshot = self::currentSnapshot($record);

        if (! $snapshot instanceof ProductTaxonomySnapshot) {
            return 'No current taxonomy snapshot is available.';
        }

        $proposal = HumanTaxonomyProposal::fromForm($form, $snapshot->primaryCategoryId);
        $options = app(LoadGiftTaxonomySelectOptionsAction::class)->execute();
        $preview = app(PreviewHumanTaxonomyProposalAction::class)->execute($proposal, $snapshot, $options);

        return collect($preview->rows)
            ->map(fn (array $row): string => $row['dimension']."\n  ".implode("\n  ", $row['changes']))
            ->implode("\n\n");
    }

    public static function pendingReclassify(?Product $record): bool
    {
        $decision = $record?->currentCurationDecision;

        return $decision?->decision === ProductCurationDecision::Reclassify
            && $decision->remediation_status === ProductCurationRemediationStatus::Pending;
    }
}
