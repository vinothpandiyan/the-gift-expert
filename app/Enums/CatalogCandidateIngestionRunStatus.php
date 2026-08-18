<?php

namespace App\Enums;

enum CatalogCandidateIngestionRunStatus: string
{
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
}
