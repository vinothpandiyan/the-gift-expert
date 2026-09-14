<?php

namespace App\GapSourcing;

use App\Enums\GapSourcingTriage;

readonly class GapSourcingShortlistItem
{
    public function __construct(
        public GapSourcingCandidate $candidate,
        public CatalogConceptMatch $nearest,
        public GapSourcingTriage $triage,
        public string $gapContribution,
        public string $priceBand,
        public bool $wishlistEligible,
        public string $rejectReason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product' => $this->candidate->title,
            'price' => $this->candidate->priceAmount,
            'price_band' => $this->priceBand,
            'target_gap' => $this->candidate->targetGap,
            'concept' => $this->candidate->concept,
            'likely_gift_intent' => $this->candidate->giftIntents,
            'interest' => $this->candidate->interest,
            'why_it_is_interesting' => $this->candidate->whyInteresting,
            'father_specific_reason' => $this->candidate->fatherSpecificReason,
            'existing_nearest_catalog_alternative' => $this->nearest->toArray(),
            'gift_potential' => $this->candidate->giftPotential,
            'gap_contribution' => $this->gapContribution,
            'evidence_confidence' => $this->candidate->evidenceConfidence,
            'evidence' => $this->candidate->evidence(),
            'wishlist_eligible' => $this->wishlistEligible,
            'triage' => $this->triage->value,
            'reject_reason' => $this->rejectReason === '' ? null : $this->rejectReason,
            'experience' => [
                'experience_type' => $this->candidate->experienceType,
                'location_restrictions' => $this->candidate->locationRestrictions,
                'price' => $this->candidate->priceAmount,
                'validity' => $this->candidate->validity,
                'redemption_method' => $this->candidate->redemptionMethod,
                'affiliate_viability' => $this->candidate->affiliateViability,
                'recipient_suitability' => $this->candidate->recipientSuitability,
            ],
            'candidate' => $this->candidate->toArray(),
        ];
    }
}
