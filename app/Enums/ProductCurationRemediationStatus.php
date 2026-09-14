<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProductCurationRemediationStatus: string implements HasColor, HasLabel
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Completed = 'completed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::NotRequired => 'Not required',
            self::Pending => 'Pending',
            self::Completed => 'Completed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::NotRequired => 'gray',
            self::Pending => 'warning',
            self::Completed => 'success',
        };
    }
}
