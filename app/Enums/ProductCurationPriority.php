<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProductCurationPriority: string implements HasColor, HasLabel
{
    case P0 = 'p0';
    case P1 = 'p1';
    case P2 = 'p2';
    case P3 = 'p3';
    case P4 = 'p4';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::P0 => 'P0 — Integrity',
            self::P1 => 'P1 — Redundancy',
            self::P2 => 'P2 — Taxonomy',
            self::P3 => 'P3 — Quality',
            self::P4 => 'P4 — Advisory',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::P0 => 'danger',
            self::P1 => 'warning',
            self::P2 => 'info',
            self::P3 => 'gray',
            self::P4 => 'success',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::P0 => 0,
            self::P1 => 1,
            self::P2 => 2,
            self::P3 => 3,
            self::P4 => 4,
        };
    }

    public function isMandatory(): bool
    {
        return $this !== self::P4;
    }
}
