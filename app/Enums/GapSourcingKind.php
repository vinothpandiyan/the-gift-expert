<?php

namespace App\Enums;

enum GapSourcingKind: string
{
    case Inventory = 'inventory';
    case Publication = 'publication';
    case Covered = 'covered';
}
