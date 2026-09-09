<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TaxonomyClassificationStatus: string implements HasColor, HasLabel
{
    case None = 'none';
    case AiProposed = 'ai_proposed';
    case AiAccepted = 'ai_accepted';
    case Review = 'review';
    case Failed = 'failed';
    case HumanApproved = 'human_approved';
    case HumanOverridden = 'human_overridden';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::None => 'Unclassified',
            self::AiProposed => 'AI proposed',
            self::AiAccepted => 'AI accepted',
            self::Review => 'Needs review',
            self::Failed => 'Failed',
            self::HumanApproved => 'Human approved',
            self::HumanOverridden => 'Human overridden',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::AiAccepted, self::HumanApproved => 'success',
            self::Review, self::AiProposed => 'warning',
            self::Failed => 'danger',
            self::HumanOverridden => 'info',
            self::None => 'gray',
        };
    }

    public function isHumanLocked(): bool
    {
        return $this === self::HumanApproved || $this === self::HumanOverridden;
    }

    public function isAiManaged(): bool
    {
        return ! $this->isHumanLocked();
    }

    public function isPublishable(): bool
    {
        return in_array($this, [
            self::AiAccepted,
            self::HumanApproved,
            self::HumanOverridden,
        ], true);
    }

    public function blocksPublication(): bool
    {
        return in_array($this, [
            self::None,
            self::AiProposed,
            self::Review,
            self::Failed,
        ], true);
    }

    public function allowsExplicitReclassify(): bool
    {
        return in_array($this, [
            self::Review,
            self::Failed,
            self::AiAccepted,
            self::HumanApproved,
            self::HumanOverridden,
        ], true);
    }
}
