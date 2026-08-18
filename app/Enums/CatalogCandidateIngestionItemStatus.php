<?php

namespace App\Enums;

enum CatalogCandidateIngestionItemStatus: string
{
    case Succeeded = 'succeeded';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
