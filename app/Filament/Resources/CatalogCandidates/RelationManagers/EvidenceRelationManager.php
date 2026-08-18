<?php

namespace App\Filament\Resources\CatalogCandidates\RelationManagers;

use App\Actions\CatalogCandidate\CreateCatalogCandidateEvidenceAction;
use App\Enums\CatalogCandidateSourceType;
use App\Filament\Resources\CatalogCandidates\CatalogCandidateResource;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateEvidence;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EvidenceRelationManager extends RelationManager
{
    protected static string $relationship = 'evidence';

    protected static ?string $title = 'Evidence';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('source_type')
                    ->options(CatalogCandidateResource::enumOptions(CatalogCandidateSourceType::class))
                    ->required(),
                TextInput::make('source_name')
                    ->maxLength(120),
                TextInput::make('source_url')
                    ->maxLength(2000)
                    ->columnSpanFull(),
                Textarea::make('summary')
                    ->rows(3)
                    ->helperText('Short note or snippet only. Do not paste full page HTML.')
                    ->columnSpanFull(),
                DateTimePicker::make('observed_at'),
                KeyValue::make('metadata')
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('source_url')
            ->columns([
                TextColumn::make('source_type')
                    ->badge(),
                TextColumn::make('source_name')
                    ->toggleable(),
                TextColumn::make('source_url')
                    ->limit(40)
                    ->wrap(),
                TextColumn::make('observed_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(function (array $data): CatalogCandidateEvidence {
                        /** @var CatalogCandidate $candidate */
                        $candidate = $this->getOwnerRecord();

                        return app(CreateCatalogCandidateEvidenceAction::class)->execute($candidate, $data);
                    }),
            ])
            ->recordActions([
                DeleteAction::make(),
            ]);
    }
}
