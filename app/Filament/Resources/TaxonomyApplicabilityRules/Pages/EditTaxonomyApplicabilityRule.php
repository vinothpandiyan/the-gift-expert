<?php

namespace App\Filament\Resources\TaxonomyApplicabilityRules\Pages;

use App\Filament\Resources\TaxonomyApplicabilityRules\TaxonomyApplicabilityRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTaxonomyApplicabilityRule extends EditRecord
{
    protected static string $resource = TaxonomyApplicabilityRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
