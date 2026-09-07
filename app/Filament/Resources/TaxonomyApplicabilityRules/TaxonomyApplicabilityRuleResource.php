<?php

namespace App\Filament\Resources\TaxonomyApplicabilityRules;

use App\Enums\TaxonomyApplicabilityEffect;
use App\Enums\TaxonomyDimension;
use App\Filament\Resources\TaxonomyApplicabilityRules\Pages\CreateTaxonomyApplicabilityRule;
use App\Filament\Resources\TaxonomyApplicabilityRules\Pages\EditTaxonomyApplicabilityRule;
use App\Filament\Resources\TaxonomyApplicabilityRules\Pages\ListTaxonomyApplicabilityRules;
use App\Models\TaxonomyApplicabilityRule;
use App\Rules\ValidTaxonomyApplicabilityPair;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TaxonomyApplicabilityRuleResource extends Resource
{
    protected static ?string $model = TaxonomyApplicabilityRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static string|\UnitEnum|null $navigationGroup = 'Taxonomies';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Applicability Rules';

    protected static ?string $modelLabel = 'applicability rule';

    protected static ?string $pluralModelLabel = 'Applicability rules';

    protected static ?string $recordTitleAttribute = 'reason';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->description('Combinations are allowed unless a rule restricts them. EXCLUDE hides a pair in both listing directions. ALLOW on a source value restricts that value to the listed targets of the other dimension.')
                    ->schema([
                        Select::make('source_dimension')
                            ->label('Source type')
                            ->options(self::enumOptions(TaxonomyDimension::class))
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('source_id', null)),
                        Select::make('source_id')
                            ->label('Source value')
                            ->options(fn (Get $get): array => TaxonomyDimension::tryFrom((string) $get('source_dimension'))?->valueOptions() ?? [])
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live(),
                        Select::make('target_dimension')
                            ->label('Target type')
                            ->options(self::enumOptions(TaxonomyDimension::class))
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('target_id', null)),
                        Select::make('target_id')
                            ->label('Target value')
                            ->options(fn (Get $get): array => TaxonomyDimension::tryFrom((string) $get('target_dimension'))?->valueOptions() ?? [])
                            ->searchable()
                            ->preload()
                            ->required()
                            ->rules([
                                fn (Get $get, ?TaxonomyApplicabilityRule $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                    $rule = new ValidTaxonomyApplicabilityPair($record?->id);
                                    $rule->setData([
                                        'source_dimension' => $get('source_dimension'),
                                        'source_id' => $get('source_id'),
                                        'target_dimension' => $get('target_dimension'),
                                        'target_id' => $value,
                                    ]);
                                    $rule->validate($attribute, $value, $fail);
                                },
                            ]),
                        Select::make('effect')
                            ->options(self::enumOptions(TaxonomyApplicabilityEffect::class))
                            ->required()
                            ->default(TaxonomyApplicabilityEffect::Exclude->value),
                        Toggle::make('is_active')
                            ->default(true),
                        Textarea::make('reason')
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source_display')
                    ->label('Source')
                    ->getStateUsing(fn (TaxonomyApplicabilityRule $record): string => $record->sourceDisplayName())
                    ->wrap(),
                TextColumn::make('target_display')
                    ->label('Target')
                    ->getStateUsing(fn (TaxonomyApplicabilityRule $record): string => $record->targetDisplayName())
                    ->wrap(),
                TextColumn::make('effect')
                    ->badge()
                    ->color(fn (TaxonomyApplicabilityEffect $state): string => match ($state) {
                        TaxonomyApplicabilityEffect::Exclude => 'danger',
                        TaxonomyApplicabilityEffect::Allow => 'success',
                    }),
                TextColumn::make('reason')
                    ->limit(60)
                    ->wrap()
                    ->searchable(),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('effect')
                    ->options(self::enumOptions(TaxonomyApplicabilityEffect::class)),
                SelectFilter::make('source_dimension')
                    ->label('Source type')
                    ->options(self::enumOptions(TaxonomyDimension::class)),
                SelectFilter::make('target_dimension')
                    ->label('Target type')
                    ->options(self::enumOptions(TaxonomyDimension::class)),
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxonomyApplicabilityRules::route('/'),
            'create' => CreateTaxonomyApplicabilityRule::route('/create'),
            'edit' => EditTaxonomyApplicabilityRule::route('/{record}/edit'),
        ];
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return array<string, string>
     */
    public static function enumOptions(string $enum): array
    {
        return collect($enum::cases())->mapWithKeys(
            fn (BackedEnum $case): array => [
                $case->value => $case instanceof HasLabel
                    ? (string) $case->getLabel()
                    : $case->name,
            ],
        )->all();
    }
}
