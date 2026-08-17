<?php

namespace App\Enums;

enum ImportRunItemStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
