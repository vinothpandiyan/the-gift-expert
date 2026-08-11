<?php

namespace App\Filament\Resources\Gifts\RelationManagers;

use App\Enums\AffiliateLinkStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AffiliateLinksRelationManager extends RelationManager
{
    protected static string $relationship = 'affiliateLinks';

    protected static ?string $title = 'Affiliate links';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('merchant_id')
                    ->relationship('merchant', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('url')
                    ->url()
                    ->required()
                    ->maxLength(2000),
                TextInput::make('external_product_id')
                    ->maxLength(120),
                Select::make('status')
                    ->options(collect(AffiliateLinkStatus::cases())->mapWithKeys(
                        fn (AffiliateLinkStatus $status): array => [$status->value => ucfirst($status->value)],
                    ))
                    ->default(AffiliateLinkStatus::Active->value)
                    ->required(),
                Toggle::make('is_primary')
                    ->default(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('url')
            ->columns([
                TextColumn::make('merchant.name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('url')
                    ->limit(40)
                    ->wrap(),
                TextColumn::make('status')
                    ->badge(),
                IconColumn::make('is_primary')
                    ->boolean(),
                TextColumn::make('external_product_id')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
