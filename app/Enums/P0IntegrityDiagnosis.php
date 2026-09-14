<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum P0IntegrityDiagnosis: string implements HasColor, HasLabel
{
    case CatalogDefect = 'catalog_defect';
    case AuditAnomaly = 'audit_anomaly';
    case Uncertain = 'uncertain';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::CatalogDefect => 'Catalog data defect',
            self::AuditAnomaly => 'Historical audit anomaly',
            self::Uncertain => 'Uncertain — defer if needed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::CatalogDefect => 'danger',
            self::AuditAnomaly => 'warning',
            self::Uncertain => 'gray',
        };
    }
}
