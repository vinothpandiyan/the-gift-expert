<?php

namespace App\Enums;

enum TaxonomyGapSeverity: string
{
    case Advisory = 'advisory';
    case Blocking = 'blocking';
}
