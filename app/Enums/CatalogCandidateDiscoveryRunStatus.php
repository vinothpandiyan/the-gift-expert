<?php

namespace App\Enums;

enum CatalogCandidateDiscoveryRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
}
