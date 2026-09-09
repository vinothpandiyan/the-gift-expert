<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EditorialOwnership: string implements HasColor, HasLabel
{
    case Source = 'source';
    case Ai = 'ai';
    case Human = 'human';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Source => 'Source copy',
            self::Ai => 'AI-generated',
            self::Human => 'Human-owned',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Source => 'gray',
            self::Ai => 'info',
            self::Human => 'success',
        };
    }
}
