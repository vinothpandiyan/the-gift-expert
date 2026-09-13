<?php

namespace App\Enums;

enum CatalogRole: string
{
    case BestOverall = 'best_overall';
    case BestValue = 'best_value';
    case PremiumPick = 'premium_pick';
    case UniquePick = 'unique_pick';
    case NichePick = 'niche_pick';
    case Undifferentiated = 'undifferentiated';
}
