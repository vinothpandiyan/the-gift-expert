<?php

namespace App\Filament\Resources\NavigationMenus;

use App\Actions\Navigation\ValidateNavigationTargetAction;
use App\Enums\NavigationItemType;
use App\Enums\NavigationLinkType;
use App\Enums\NavigationSectionAppearance;
use App\Filament\Resources\NavigationMenus\Pages\CreateNavigationMenu;
use App\Filament\Resources\NavigationMenus\Pages\EditNavigationMenu;
use App\Filament\Resources\NavigationMenus\Pages\ListNavigationMenus;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\NavigationMenu;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class NavigationMenuResource extends Resource
{
    protected static ?string $model = NavigationMenu::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static string|\UnitEnum|null $navigationGroup = 'Site';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'label';

    public static function getModelLabel(): string
    {
        return 'Navigation menu';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Navigation menus';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Menu')
                    ->schema([
                        TextInput::make('label')
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
                            ->unique(ignoreRecord: true),
                        Select::make('item_type')
                            ->options(self::enumOptions(NavigationItemType::cases()))
                            ->default(NavigationItemType::Mega->value)
                            ->required()
                            ->live(),
                        TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->required(),
                        Toggle::make('is_active')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Link target')
                    ->schema(self::targetFields(topLevel: true))
                    ->visible(fn (Get $get): bool => self::isLinkMenu($get('item_type')))
                    ->columns(2),
                Section::make('Mega menu')
                    ->schema([
                        Repeater::make('sections')
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->reorderable()
                            ->defaultItems(0)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['heading'] ?? 'Section')
                            ->addActionLabel('Add section')
                            ->schema([
                                TextInput::make('heading')
                                    ->maxLength(120),
                                Select::make('appearance')
                                    ->options(self::enumOptions(NavigationSectionAppearance::cases()))
                                    ->default(NavigationSectionAppearance::Default->value)
                                    ->required(),
                                Toggle::make('is_active')
                                    ->default(true),
                                Repeater::make('links')
                                    ->relationship()
                                    ->orderColumn('sort_order')
                                    ->reorderable()
                                    ->defaultItems(0)
                                    ->collapsible()
                                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? 'Link')
                                    ->addActionLabel('Add link')
                                    ->mutateRelationshipDataBeforeCreateUsing(
                                        fn (array $data): array => app(ValidateNavigationTargetAction::class)->sanitize($data),
                                    )
                                    ->mutateRelationshipDataBeforeSaveUsing(
                                        fn (array $data): array => app(ValidateNavigationTargetAction::class)->sanitize($data),
                                    )
                                    ->schema([
                                        TextInput::make('label')
                                            ->required()
                                            ->maxLength(120),
                                        ...self::targetFields(),
                                        Toggle::make('is_active')
                                            ->default(true),
                                    ])
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->visible(fn (Get $get): bool => ! self::isLinkMenu($get('item_type'))),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('label')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('item_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                TextColumn::make('sections_count')
                    ->counts('sections')
                    ->label('Sections'),
            ])
            ->filters([
                SelectFilter::make('item_type')
                    ->options(self::enumOptions(NavigationItemType::cases())),
                SelectFilter::make('is_active')
                    ->label('Active')
                    ->options([
                        '1' => 'Yes',
                        '0' => 'No',
                    ]),
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
            ->defaultSort('sort_order')
            ->reorderable('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNavigationMenus::route('/'),
            'create' => CreateNavigationMenu::route('/create'),
            'edit' => EditNavigationMenu::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('sections');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateMenuData(array $data): array
    {
        if (! self::isLinkMenu($data['item_type'] ?? null)) {
            $data['link_type'] = null;
            $data['linkable_id'] = null;
            $data['route_key'] = null;
            $data['url'] = null;
            $data['opens_in_new_tab'] = false;

            return $data;
        }

        return app(ValidateNavigationTargetAction::class)->sanitize($data);
    }

    /**
     * @return list<Component>
     */
    public static function targetFields(bool $topLevel = false): array
    {
        return [
            Select::make('link_type')
                ->options(self::enumOptions(NavigationLinkType::cases()))
                ->live()
                ->required(fn (Get $get): bool => ! $topLevel || self::isLinkMenu($get('item_type')))
                ->afterStateUpdated(function (Set $set): void {
                    $set('linkable_id', null);
                    $set('route_key', null);
                    $set('url', null);
                })
                ->rules([
                    fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $topLevel): void {
                        if ($topLevel && ! self::isLinkMenu($get('item_type'))) {
                            return;
                        }

                        $type = $value instanceof NavigationLinkType
                            ? $value
                            : (is_string($value) ? NavigationLinkType::tryFrom($value) : null);
                        $errors = app(ValidateNavigationTargetAction::class)->execute(
                            $type,
                            filled($get('linkable_id')) ? (int) $get('linkable_id') : null,
                            $get('route_key'),
                            $get('url'),
                            required: true,
                        );

                        if ($errors !== []) {
                            $fail(reset($errors));
                        }
                    },
                ]),
            Select::make('linkable_id')
                ->label('Target')
                ->searchable()
                ->preload()
                ->options(fn (Get $get): array => self::entityOptions($get('link_type')))
                ->visible(fn (Get $get): bool => self::usesEntityTarget($get('link_type')))
                ->required(fn (Get $get): bool => self::usesEntityTarget($get('link_type'))),
            Select::make('route_key')
                ->label('Discovery route')
                ->options(fn (): array => ValidateNavigationTargetAction::selectableDiscoveryRoutes())
                ->visible(fn (Get $get): bool => self::linkTypeIs($get('link_type'), NavigationLinkType::DiscoveryRoute))
                ->required(fn (Get $get): bool => self::linkTypeIs($get('link_type'), NavigationLinkType::DiscoveryRoute)),
            TextInput::make('url')
                ->label('URL')
                ->maxLength(500)
                ->visible(fn (Get $get): bool => self::linkTypeIs($get('link_type'), NavigationLinkType::ExternalUrl))
                ->required(fn (Get $get): bool => self::linkTypeIs($get('link_type'), NavigationLinkType::ExternalUrl)),
            Toggle::make('opens_in_new_tab')
                ->default(false),
        ];
    }

    private static function isLinkMenu(mixed $itemType): bool
    {
        if ($itemType instanceof NavigationItemType) {
            return $itemType === NavigationItemType::Link;
        }

        return $itemType === NavigationItemType::Link->value;
    }

    private static function usesEntityTarget(mixed $linkType): bool
    {
        $type = $linkType instanceof NavigationLinkType
            ? $linkType
            : NavigationLinkType::tryFrom((string) $linkType);

        return in_array($type, [
            NavigationLinkType::Relationship,
            NavigationLinkType::Occasion,
            NavigationLinkType::Interest,
            NavigationLinkType::Profession,
            NavigationLinkType::RecipientType,
            NavigationLinkType::GiftType,
            NavigationLinkType::Category,
            NavigationLinkType::SeoLandingPage,
        ], true);
    }

    private static function linkTypeIs(mixed $linkType, NavigationLinkType $expected): bool
    {
        if ($linkType instanceof NavigationLinkType) {
            return $linkType === $expected;
        }

        return $linkType === $expected->value;
    }

    /**
     * @return array<int|string, string>
     */
    private static function entityOptions(mixed $linkType): array
    {
        $type = $linkType instanceof NavigationLinkType
            ? $linkType
            : NavigationLinkType::tryFrom((string) $linkType);

        return match ($type) {
            NavigationLinkType::Relationship => Relationship::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            NavigationLinkType::Occasion => Occasion::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            NavigationLinkType::Interest => Interest::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            NavigationLinkType::Profession => Profession::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            NavigationLinkType::RecipientType => RecipientType::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            NavigationLinkType::GiftType => GiftType::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            NavigationLinkType::Category => Category::query()
                ->where('is_active', true)
                ->orderBy('full_path')
                ->get()
                ->mapWithKeys(fn (Category $category): array => [
                    $category->id => $category->name.' ('.$category->full_path.')',
                ])
                ->all(),
            NavigationLinkType::SeoLandingPage => SeoLandingPage::query()
                ->discoverable()
                ->orderBy('heading')
                ->pluck('heading', 'id')
                ->all(),
            default => [],
        };
    }

    /**
     * @param  list<BackedEnum>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        return collect($cases)->mapWithKeys(
            fn (BackedEnum $case): array => [$case->value => str($case->value)->replace('_', ' ')->headline()->toString()],
        )->all();
    }
}
