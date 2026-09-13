<?php

namespace App\Enums;

enum CurationIssueCode: string
{
    case WeakGiftFit = 'weak_gift_fit';
    case LowAiConfidence = 'low_ai_confidence';
    case RelationshipOverclassification = 'relationship_overclassification';
    case OccasionOverclassification = 'occasion_overclassification';
    case InterestMismatch = 'interest_mismatch';
    case GiftTypeMismatch = 'gift_type_mismatch';
    case TaxonomyConflict = 'taxonomy_conflict';
    case TaxonomyFitAdvisory = 'taxonomy_fit_advisory';
    case HardTaxonomyConflict = 'hard_taxonomy_conflict';
    case UnresolvedTaxonomyLabel = 'unresolved_taxonomy_label';
    case MissingCurrentAssignmentEvaluation = 'missing_current_assignment_evaluation';
    case MissingPrimaryCategory = 'missing_primary_category';
    case WeakWhyThisGift = 'weak_why_this_gift';
    case ConceptOversaturated = 'concept_oversaturated';
    case PossibleConceptDuplicate = 'possible_concept_duplicate';
    case LowCatalogValue = 'low_catalog_value';
    case WeakValueForMoney = 'weak_value_for_money';
    case WeakProductConfidence = 'weak_product_confidence';
    case MissingCommerceEvidence = 'missing_commerce_evidence';
    case SemanticResponseInvalid = 'semantic_response_invalid';
}
