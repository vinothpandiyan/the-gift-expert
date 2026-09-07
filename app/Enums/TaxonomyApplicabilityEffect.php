<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TaxonomyApplicabilityEffect: string implements HasLabel
{
    case Allow = 'allow';
    case Exclude = 'exclude';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Allow => 'Allow',
            self::Exclude => 'Exclude',
        };
    }
}
