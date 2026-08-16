<?php

namespace App\Filament\Resources\SeoLandingPages;

use App\Enums\SeoLandingPageStatus;
use App\Filament\Resources\SeoLandingPages\Pages\CreateSeoLandingPage;
use App\Filament\Resources\SeoLandingPages\Pages\EditSeoLandingPage;
use App\Filament\Resources\SeoLandingPages\Pages\ListSeoLandingPages;
use App\Models\Category;
use App\Models\SeoLandingPage;
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
use Illuminate\Validation\Rule;

class SeoLandingPageResource extends Resource
{
    protected static ?string $model = SeoLandingPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return 'SEO Landing Page';
    }

    public static function getPluralModelLabel(): string
    {
        return 'SEO Landing Pages';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Basic Information')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(120)
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
                            ->unique(ignoreRecord: true)
                            ->helperText('Lowercase kebab-case, single path segment. Exact reserved slugs such as gifts or gifts-for are not allowed.')
                            ->rules([
                                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                                Rule::notIn(config('discovery.reserved_prefixes', [])),
                            ]),
                        TextInput::make('heading')
                            ->required()
                            ->maxLength(255),
                        Select::make('status')
                            ->options(collect(SeoLandingPageStatus::cases())->mapWithKeys(
                                fn (SeoLandingPageStatus $status): array => [$status->value => ucfirst($status->value)],
                            ))
                            ->default(SeoLandingPageStatus::Draft->value)
                            ->disabled()
                            ->dehydrated(),
                        TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
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
                Section::make('Search Intent / Filters')
                    ->schema([
                        Select::make('relationship_id')
                            ->label('Relationship')
                            ->relationship('relationship', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('recipient_type_id')
                            ->label('Recipient type')
                            ->relationship('recipientType', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('occasion_id')
                            ->label('Occasion')
                            ->relationship('occasion', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('profession_id')
                            ->label('Profession')
                            ->relationship('profession', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('gift_type_id')
                            ->label('Gift type')
                            ->relationship('giftType', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->getOptionLabelFromRecordUsing(fn (Category $record): string => $record->full_path)
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('budget_range_id')
                            ->label('Budget range')
                            ->relationship('budgetRange', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Select::make('interests')
                            ->label('Interests')
                            ->relationship('interests', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('SEO')
                    ->schema([
                        TextInput::make('meta_title')
                            ->maxLength(255),
                        TextInput::make('canonical_url')
                            ->url()
                            ->maxLength(500)
                            ->helperText('Optional. Leave blank to use the generated landing-page URL.'),
                        Textarea::make('meta_description')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Toggle::make('is_indexable')
                            ->default(false),
                        Toggle::make('include_in_sitemap')
                            ->default(false),
                    ])
                    ->columns(2)
                    ->collapsed(),
                Section::make('Content')
                    ->schema([
                        Textarea::make('intro_content')
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('body_content')
                            ->rows(8)
                            ->columnSpanFull(),
                        Textarea::make('faq_content')
                            ->rows(6)
                            ->columnSpanFull(),
                    ])
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
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('relationship.name')
                    ->label('Relationship')
                    ->toggleable(),
                TextColumn::make('occasion.name')
                    ->label('Occasion')
                    ->toggleable(),
                IconColumn::make('is_indexable')
                    ->boolean()
                    ->label('Indexable'),
                IconColumn::make('include_in_sitemap')
                    ->boolean()
                    ->label('Sitemap'),
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
                    ->options(collect(SeoLandingPageStatus::cases())->mapWithKeys(
                        fn (SeoLandingPageStatus $status): array => [$status->value => ucfirst($status->value)],
                    )),
                SelectFilter::make('is_indexable')
                    ->label('Indexable')
                    ->options([
                        '1' => 'Yes',
                        '0' => 'No',
                    ]),
                SelectFilter::make('include_in_sitemap')
                    ->label('Sitemap inclusion')
                    ->options([
                        '1' => 'Yes',
                        '0' => 'No',
                    ]),
                SelectFilter::make('relationship')
                    ->relationship('relationship', 'name'),
                SelectFilter::make('occasion')
                    ->relationship('occasion', 'name'),
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

    public static function getPages(): array
    {
        return [
            'index' => ListSeoLandingPages::route('/'),
            'create' => CreateSeoLandingPage::route('/create'),
            'edit' => EditSeoLandingPage::route('/{record}/edit'),
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
