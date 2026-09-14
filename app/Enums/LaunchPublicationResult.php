<?php

namespace App\Enums;

enum LaunchPublicationResult: string
{
    case Published = 'published';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
