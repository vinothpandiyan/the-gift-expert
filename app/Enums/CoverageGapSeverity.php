<?php

namespace App\Enums;

enum CoverageGapSeverity: string
{
    case Empty = 'empty';
    case Thin = 'thin';
    case Healthy = 'healthy';
}
