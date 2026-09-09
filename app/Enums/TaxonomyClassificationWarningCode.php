<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TaxonomyClassificationWarningCode: string implements HasLabel
{
    case TrustedHintAdded = 'trusted_hint_added';
    case TrustedSourceSemanticConflict = 'trusted_source_semantic_conflict';
    case TrustedHintIncompatible = 'trusted_hint_incompatible';
    case AiOptionalTaxonomyDropped = 'ai_optional_taxonomy_dropped';
    case InactiveTaxonomyIdRejected = 'inactive_taxonomy_id_rejected';
    case TaxonomyGap = 'taxonomy_gap';
    case TaxonomyGapAdvisory = 'taxonomy_gap_advisory';
    case TaxonomyGapBlocking = 'taxonomy_gap_blocking';
    case LowPrimaryCategoryConfidence = 'low_primary_category_confidence';
    case LowGiftTypeConfidence = 'low_gift_type_confidence';
    case InvalidOptionalTaxonomy = 'invalid_optional_taxonomy';
    case AiSecondaryAssignmentRemoved = 'ai_secondary_assignment_removed';
    case MissingExpectedRelationship = 'missing_expected_relationship';
    case MalformedPartialTaxonomy = 'malformed_partial_taxonomy';
    case EnrichmentFailed = 'enrichment_failed';
    case MissingPrimaryCategory = 'missing_primary_category';
    case MalformedAiResponse = 'malformed_ai_response';
    case InvalidPrimaryCategory = 'invalid_primary_category';
    case TaxonomyIdsRejected = 'taxonomy_ids_rejected';
    case HumanRejectedProposal = 'human_rejected_proposal';
    case StaleProposal = 'stale_proposal';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::TrustedHintAdded => 'Trusted source hint added',
            self::TrustedSourceSemanticConflict => 'Trusted source conflicts with product semantics',
            self::TrustedHintIncompatible => 'Trusted source hint is incompatible',
            self::AiOptionalTaxonomyDropped => 'Low-confidence optional taxonomy dropped',
            self::InactiveTaxonomyIdRejected => 'Inactive taxonomy ID rejected',
            self::TaxonomyGap => 'Possible taxonomy gap',
            self::TaxonomyGapAdvisory => 'Advisory taxonomy gap',
            self::TaxonomyGapBlocking => 'Blocking taxonomy gap',
            self::LowPrimaryCategoryConfidence => 'Low primary category confidence',
            self::LowGiftTypeConfidence => 'Low gift type confidence',
            self::InvalidOptionalTaxonomy => 'Invalid optional taxonomy',
            self::AiSecondaryAssignmentRemoved => 'AI secondary category removed',
            self::MissingExpectedRelationship => 'Expected relationship missing',
            self::MalformedPartialTaxonomy => 'Malformed partial taxonomy',
            self::EnrichmentFailed => 'AI enrichment failed',
            self::MissingPrimaryCategory => 'Missing primary category',
            self::MalformedAiResponse => 'Malformed AI response',
            self::InvalidPrimaryCategory => 'Invalid primary category',
            self::TaxonomyIdsRejected => 'Taxonomy IDs rejected',
            self::HumanRejectedProposal => 'AI proposal rejected by operator',
            self::StaleProposal => 'Proposal may be stale',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::TrustedHintAdded => 'A trusted wishlist relationship hint was added to the proposal.',
            self::TrustedSourceSemanticConflict => 'A trusted source hint conflicts with other proposed taxonomy and was not applied automatically.',
            self::TrustedHintIncompatible => 'A trusted source hint could not be combined with the rest of the proposal.',
            self::AiOptionalTaxonomyDropped => 'Optional taxonomy was dropped because confidence was below the configured keep threshold.',
            self::InactiveTaxonomyIdRejected => 'The model selected taxonomy that is inactive or not allowed.',
            self::TaxonomyGap => 'The model suggested a concept that is not in the current taxonomy.',
            self::TaxonomyGapAdvisory => 'A more specific merchandising leaf could help later. The selected Category is still valid.',
            self::TaxonomyGapBlocking => 'The product cannot be represented honestly by the current Category taxonomy.',
            self::LowPrimaryCategoryConfidence => 'Primary category confidence is below the auto-accept threshold.',
            self::LowGiftTypeConfidence => 'Gift type confidence is below the auto-accept threshold.',
            self::InvalidOptionalTaxonomy => 'Optional taxonomy IDs were invalid against the current catalog.',
            self::AiSecondaryAssignmentRemoved => 'Secondary category assignments from the model were removed during ancestor normalization.',
            self::MissingExpectedRelationship => 'The proposal is missing a relationship that source provenance suggested.',
            self::MalformedPartialTaxonomy => 'Part of the model output could not be parsed as taxonomy.',
            self::EnrichmentFailed => 'The enrichment call failed before a complete proposal could be stored.',
            self::MissingPrimaryCategory => 'No valid primary merchandising category was produced.',
            self::MalformedAiResponse => 'The model response was not valid JSON for classification.',
            self::InvalidPrimaryCategory => 'The primary category is not an active merchandising category.',
            self::TaxonomyIdsRejected => 'One or more taxonomy IDs were rejected against the current catalog.',
            self::HumanRejectedProposal => 'An operator rejected this AI proposal. The gift remains a draft.',
            self::StaleProposal => 'Taxonomy version, source title, or relationship hints changed since this proposal was generated.',
        };
    }

    public static function labelFor(string $code): string
    {
        $case = self::tryFrom($code);

        return $case?->getLabel() ?? str($code)->replace('_', ' ')->ucfirst()->toString();
    }

    public static function descriptionFor(string $code): string
    {
        $case = self::tryFrom($code);

        return $case?->getDescription() ?? $code;
    }

    /**
     * @return array<string, string>
     */
    public static function filterOptions(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $code): array => [$code->value => $code->getLabel() ?? $code->value])
            ->all();
    }
}
