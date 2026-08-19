<?php

namespace App\Enums;

enum CatalogCandidateSourcingItemStatus: string
{
    case Succeeded = 'succeeded';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
