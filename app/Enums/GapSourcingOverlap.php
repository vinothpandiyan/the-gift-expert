<?php

namespace App\Enums;

enum GapSourcingOverlap: string
{
    case ExactAsinAlreadyInCatalog = 'exact_asin_already_in_catalog';
    case ExactConceptAlreadyStrong = 'exact_concept_already_strong';
    case ConceptExistsButWeak = 'concept_exists_but_weak';
    case ConceptExistsDifferentBudget = 'concept_exists_different_budget';
    case ConceptExistsDifferentIntent = 'concept_exists_different_intent';
    case GenuineNewConcept = 'genuine_new_concept';
}
