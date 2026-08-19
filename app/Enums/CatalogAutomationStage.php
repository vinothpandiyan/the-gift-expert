<?php

namespace App\Enums;

enum CatalogAutomationStage: string
{
    case Discovery = 'discovery';
    case Sourcing = 'sourcing';
    case Enrichment = 'enrichment';
    case Promotion = 'promotion';
    case Readiness = 'readiness';

    public function includesSourcing(): bool
    {
        return match ($this) {
            self::Discovery => false,
            default => true,
        };
    }

    public function enrich(): bool
    {
        return match ($this) {
            self::Discovery, self::Sourcing => false,
            default => true,
        };
    }

    public function promote(): bool
    {
        return match ($this) {
            self::Discovery, self::Sourcing, self::Enrichment => false,
            default => true,
        };
    }

    public function reEvaluateReadiness(): bool
    {
        return $this === self::Readiness;
    }
}
