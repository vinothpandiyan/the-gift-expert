<?php

namespace App\Filament\Resources\TaxonomyApplicabilityRules\Pages;

use App\Filament\Resources\TaxonomyApplicabilityRules\TaxonomyApplicabilityRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTaxonomyApplicabilityRules extends ListRecords
{
    protected static string $resource = TaxonomyApplicabilityRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
