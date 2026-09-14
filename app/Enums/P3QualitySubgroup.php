<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum P3QualitySubgroup: string implements HasColor, HasLabel
{
    case LowGiftScore = 'p3_a';
    case LowCatalogValue = 'p3_b';
    case WeakEvidence = 'p3_c';
    case LowConfidence = 'p3_d';
    case RemainingQuality = 'p3_e';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::LowGiftScore => 'P3-A — Low Gift Score',
            self::LowCatalogValue => 'P3-B — Low Catalog Value',
            self::WeakEvidence => 'P3-C — Weak evidence',
            self::LowConfidence => 'P3-D — Low confidence',
            self::RemainingQuality => 'P3-E — Remaining quality',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::LowGiftScore => 'danger',
            self::LowCatalogValue => 'warning',
            self::WeakEvidence => 'info',
            self::LowConfidence => 'gray',
            self::RemainingQuality => 'success',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::LowGiftScore => 0,
            self::LowCatalogValue => 1,
            self::WeakEvidence => 2,
            self::LowConfidence => 3,
            self::RemainingQuality => 4,
        };
    }

    public function queueView(): string
    {
        return $this->value;
    }

    /**
     * @return list<self>
     */
    public static function casesInReviewOrder(): array
    {
        return [
            self::LowGiftScore,
            self::LowCatalogValue,
            self::WeakEvidence,
            self::LowConfidence,
            self::RemainingQuality,
        ];
    }
}
