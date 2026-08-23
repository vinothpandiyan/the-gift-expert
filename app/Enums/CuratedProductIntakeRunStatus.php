<?php

namespace App\Enums;

enum CuratedProductIntakeRunStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
}
