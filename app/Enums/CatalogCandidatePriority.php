<?php

namespace App\Enums;

enum CatalogCandidatePriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
