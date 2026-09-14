<?php

namespace App\Enums;

enum IncomingCatalogContribution: string
{
    case FillsCriticalGap = 'fills_critical_gap';
    case FillsHighPriorityGap = 'fills_high_priority_gap';
    case FillsMediumGap = 'fills_medium_gap';
    case ReplacesWeakExistingInventory = 'replaces_weak_existing_inventory';
    case AddsUsefulDifferentiation = 'adds_useful_differentiation';
    case AddsNoMeaningfulCatalogValue = 'adds_no_meaningful_catalog_value';
}
