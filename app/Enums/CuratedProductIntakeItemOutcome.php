<?php

namespace App\Enums;

enum CuratedProductIntakeItemOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
