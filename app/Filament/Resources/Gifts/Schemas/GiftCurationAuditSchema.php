<?php

namespace App\Filament\Resources\Gifts\Schemas;

use App\Models\Product;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;

class GiftCurationAuditSchema
{
    public static function reviewSection(): Section
    {
        return Section::make('Curation Audit')
            ->description('Read-only review of the latest completed catalog curation audit.')
            ->schema([
                View::make('filament.gifts.partials.curation-audit-review')
                    ->viewData(fn (?Product $record): array => [
                        'reviewProduct' => $record,
                    ])
                    ->columnSpanFull(),
            ])
            ->extraAttributes([
                'class' => 'min-w-0 col-span-full',
                'data-curation-audit-section' => '',
            ])
            ->columns(1)
            ->columnSpanFull()
            ->visibleOn('edit')
            ->collapsible();
    }
}
