<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CatalogSourceListKind: string implements HasLabel
{
    case RecipientHint = 'recipient_hint';
    case UnclassifiedInbox = 'unclassified_inbox';
    case QuarterlyArchive = 'quarterly_archive';
    case Unknown = 'unknown';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::RecipientHint => 'Recipient hint',
            self::UnclassifiedInbox => 'Unclassified inbox',
            self::QuarterlyArchive => 'Quarterly archive',
            self::Unknown => 'Unknown',
        };
    }
}
