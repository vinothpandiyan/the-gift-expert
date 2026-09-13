<?php

namespace App\Enums;

enum CurationAiConfidence: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
        };
    }
}
