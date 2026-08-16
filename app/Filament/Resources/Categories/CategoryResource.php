<?php

namespace App\Filament\Resources\Categories;

use App\Enums\SeoLandingPageStatus;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Models\SeoLandingPage;
use BackedEnum;
use Closure;
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
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('parent_id')
                            ->label('Parent category')
                            ->relationship(
                                name: 'parent',
                                titleAttribute: 'name',
                                modifyQueryUsing: function (Builder $query, ?Category $record): Builder {
                                    if ($record === null) {
                                        return $query->orderBy('full_path');
                                    }

                                    return $query
                                        ->whereKeyNot($record->getKey())
                                        ->orderBy('full_path');
                                },
                            )
                            ->getOptionLabelFromRecordUsing(fn (Category $record): string => $record->full_path)
                            ->searchable()
                            ->preload()
                            ->nullable(),
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
                            ->rules([
                                fn (Get $get, ?Category $record): Unique => Rule::unique('categories', 'slug')
                                    ->where(fn ($query) => $query->where('parent_id', $get('parent_id')))
                                    ->ignore($record),
                            ]),
                        TextInput::make('full_path')
                            ->disabled()
                            ->dehydrated(false)
                            ->visibleOn('edit'),
                        Textarea::make('description')
                            ->columnSpanFull(),
                        TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->required(),
                        Toggle::make('is_active')
                            ->default(true),
                        Select::make('canonical_seo_landing_page_id')
                            ->label('Canonical SEO landing page')
                            ->relationship(
                                name: 'canonicalSeoLandingPage',
                                titleAttribute: 'heading',
                                modifyQueryUsing: function (Builder $query, ?Category $record): Builder {
                                    return $query
                                        ->where(function (Builder $inner) use ($record): void {
                                            $inner->where('status', SeoLandingPageStatus::Published);

                                            if ($record?->canonical_seo_landing_page_id) {
                                                $inner->orWhere('seo_landing_pages.id', $record->canonical_seo_landing_page_id);
                                            }
                                        })
                                        ->orderBy('heading');
                                },
                            )
                            ->getOptionLabelFromRecordUsing(function (SeoLandingPage $page): string {
                                $label = $page->heading.' (/'.$page->slug.')';

                                if ($page->status !== SeoLandingPageStatus::Published) {
                                    return $label.' — unpublished';
                                }

                                if (! $page->is_indexable) {
                                    return $label.' — noindex';
                                }

                                return $label;
                            })
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->live()
                            ->helperText('When the landing page is published, this category’s public URL 301s to it. The category row and product pivots are kept. Map only to a published page; prefer an indexable page so the category URL is not redirected to a noindex target.')
                            ->rules([
                                function (): Closure {
                                    return function (string $attribute, mixed $value, Closure $fail): void {
                                        if ($value === null || $value === '') {
                                            return;
                                        }

                                        $page = SeoLandingPage::query()->find($value);

                                        if ($page === null || $page->status !== SeoLandingPageStatus::Published) {
                                            $fail('Map this category only to a published SEO landing page.');
                                        }
                                    };
                                },
                            ]),
                        Callout::make('This landing page is not indexable')
                            ->description('The category URL will still 301 to the published landing page, but that page is noindex. Prefer mapping after the landing page is indexable.')
                            ->warning()
                            ->columnSpanFull()
                            ->visible(function (Get $get): bool {
                                $id = $get('canonical_seo_landing_page_id');

                                if ($id === null || $id === '') {
                                    return false;
                                }

                                $page = SeoLandingPage::query()->find($id);

                                return $page instanceof SeoLandingPage
                                    && $page->status === SeoLandingPageStatus::Published
                                    && ! $page->is_indexable;
                            }),
                        TextInput::make('meta_title')
                            ->maxLength(255),
                        Textarea::make('meta_description')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
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
                TextColumn::make('full_path')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('parent.name')
                    ->label('Parent')
                    ->toggleable(),
                TextColumn::make('sort_order')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([
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
            ->defaultSort('full_path');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
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
