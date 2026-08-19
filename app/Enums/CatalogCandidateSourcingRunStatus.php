<?php

namespace App\Enums;

enum CatalogCandidateSourcingRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
}
