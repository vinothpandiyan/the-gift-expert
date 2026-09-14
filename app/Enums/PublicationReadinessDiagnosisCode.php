<?php

namespace App\Enums;

enum PublicationReadinessDiagnosisCode: string
{
    case MissingPrimaryCategoryOnly = 'missing_primary_category_only';
    case ClassificationLifecycleOnly = 'classification_lifecycle_only';
    case Both = 'both';
    case TaxonomyAmbiguity = 'taxonomy_ambiguity';
    case HistoricalStaleState = 'historical_stale_state';

    public function letter(): string
    {
        return match ($this) {
            self::MissingPrimaryCategoryOnly => 'A',
            self::ClassificationLifecycleOnly => 'B',
            self::Both => 'C',
            self::TaxonomyAmbiguity => 'D',
            self::HistoricalStaleState => 'E',
        };
    }
}
