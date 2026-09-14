<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProductCurationDecisionReasonCode: string implements HasLabel
{
    case StrongGift = 'strong_gift';
    case StrongCatalogValue = 'strong_catalog_value';
    case UniqueConcept = 'unique_concept';
    case FillsCatalogGap = 'fills_catalog_gap';
    case StrongPersonalization = 'strong_personalization';
    case StrongEmotionalValue = 'strong_emotional_value';
    case StrongPracticalValue = 'strong_practical_value';
    case GoodValueForMoney = 'good_value_for_money';

    case RedundantConcept = 'redundant_concept';
    case WeakerThanPeer = 'weaker_than_peer';
    case WeakGiftFit = 'weak_gift_fit';
    case WeakCatalogValue = 'weak_catalog_value';
    case GenericProduct = 'generic_product';
    case PoorValueForMoney = 'poor_value_for_money';
    case WeakDifferentiation = 'weak_differentiation';

    case TaxonomyRelationshipIssue = 'taxonomy_relationship_issue';
    case TaxonomyOccasionIssue = 'taxonomy_occasion_issue';
    case TaxonomyInterestIssue = 'taxonomy_interest_issue';
    case TaxonomyGiftTypeIssue = 'taxonomy_gift_type_issue';
    case TaxonomyCategoryIssue = 'taxonomy_category_issue';

    case MissingEvidence = 'missing_evidence';
    case MalformedEvidence = 'malformed_evidence';
    case CommerceIssue = 'commerce_issue';
    case AuditAnomaly = 'audit_anomaly';

    case NeedsMoreResearch = 'needs_more_research';
    case Other = 'other';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::StrongGift => 'Strong gift',
            self::StrongCatalogValue => 'Strong catalog value',
            self::UniqueConcept => 'Unique concept',
            self::FillsCatalogGap => 'Fills a catalog gap',
            self::StrongPersonalization => 'Strong personalization',
            self::StrongEmotionalValue => 'Strong emotional value',
            self::StrongPracticalValue => 'Strong practical value',
            self::GoodValueForMoney => 'Good value for money',
            self::RedundantConcept => 'Redundant concept',
            self::WeakerThanPeer => 'Weaker than a peer',
            self::WeakGiftFit => 'Weak gift fit',
            self::WeakCatalogValue => 'Weak catalog value',
            self::GenericProduct => 'Generic product',
            self::PoorValueForMoney => 'Poor value for money',
            self::WeakDifferentiation => 'Weak differentiation',
            self::TaxonomyRelationshipIssue => 'Relationship taxonomy issue',
            self::TaxonomyOccasionIssue => 'Occasion taxonomy issue',
            self::TaxonomyInterestIssue => 'Interest taxonomy issue',
            self::TaxonomyGiftTypeIssue => 'Gift type taxonomy issue',
            self::TaxonomyCategoryIssue => 'Category taxonomy issue',
            self::MissingEvidence => 'Missing evidence',
            self::MalformedEvidence => 'Malformed evidence',
            self::CommerceIssue => 'Commerce issue',
            self::AuditAnomaly => 'Audit anomaly',
            self::NeedsMoreResearch => 'Needs more research',
            self::Other => 'Other',
        };
    }

    public function isTaxonomy(): bool
    {
        return in_array($this, [
            self::TaxonomyRelationshipIssue,
            self::TaxonomyOccasionIssue,
            self::TaxonomyInterestIssue,
            self::TaxonomyGiftTypeIssue,
            self::TaxonomyCategoryIssue,
        ], true);
    }

    public function isRetention(): bool
    {
        return in_array($this, [
            self::StrongGift,
            self::StrongCatalogValue,
            self::UniqueConcept,
            self::FillsCatalogGap,
            self::StrongPersonalization,
            self::StrongEmotionalValue,
            self::StrongPracticalValue,
            self::GoodValueForMoney,
        ], true);
    }

    public function isRemoval(): bool
    {
        return in_array($this, [
            self::RedundantConcept,
            self::WeakerThanPeer,
            self::WeakGiftFit,
            self::WeakCatalogValue,
            self::GenericProduct,
            self::PoorValueForMoney,
            self::WeakDifferentiation,
        ], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $code): array => [$code->value => $code->getLabel() ?? $code->value])
            ->all();
    }

    /**
     * @return list<self>
     */
    public static function fromValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->map(fn (string $value): ?self => self::tryFrom($value))
            ->filter()
            ->values()
            ->all();
    }
}
