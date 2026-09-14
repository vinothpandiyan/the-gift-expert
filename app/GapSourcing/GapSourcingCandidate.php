<?php

namespace App\GapSourcing;

use InvalidArgumentException;

readonly class GapSourcingCandidate
{
    /**
     * @param  list<string>  $features
     * @param  list<string>  $giftIntents
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $merchant,
        public string $sourceUrl,
        public string $targetGap,
        public string $concept,
        public ?string $externalId,
        public ?float $priceAmount,
        public string $priceCurrency,
        public ?float $rating,
        public ?int $reviewCount,
        public ?string $imageUrl,
        public array $features,
        public ?string $availability,
        public string $giftPotential,
        public array $giftIntents,
        public ?string $interest,
        public ?string $whyInteresting,
        public ?string $fatherSpecificReason,
        public ?string $sustainabilityEvidence,
        public bool $differentiated,
        public bool $isGiftCard,
        public ?string $experienceType,
        public ?string $locationRestrictions,
        public ?string $validity,
        public ?string $redemptionMethod,
        public ?string $affiliateViability,
        public ?string $recipientSuitability,
        public string $evidenceConfidence,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $id = self::requiredString($payload, 'id');
        $title = self::requiredString($payload, 'title');
        $merchant = self::requiredString($payload, 'merchant');
        $sourceUrl = self::requiredString($payload, 'source_url');
        $targetGap = self::requiredString($payload, 'target_gap');
        $concept = self::requiredString($payload, 'concept');

        return new self(
            id: $id,
            title: $title,
            merchant: $merchant,
            sourceUrl: $sourceUrl,
            targetGap: $targetGap,
            concept: $concept,
            externalId: self::optionalString($payload['external_id'] ?? $payload['asin'] ?? null),
            priceAmount: self::optionalFloat($payload['price_amount'] ?? $payload['price'] ?? null),
            priceCurrency: is_string($payload['price_currency'] ?? null) && $payload['price_currency'] !== ''
                ? strtoupper($payload['price_currency'])
                : 'INR',
            rating: self::optionalFloat($payload['rating'] ?? null),
            reviewCount: self::optionalInt($payload['review_count'] ?? null),
            imageUrl: self::optionalString($payload['image_url'] ?? null),
            features: self::stringList($payload['features'] ?? []),
            availability: self::optionalString($payload['availability'] ?? null),
            giftPotential: self::giftPotential($payload['gift_potential'] ?? 'medium'),
            giftIntents: self::stringList($payload['gift_intents'] ?? $payload['likely_gift_intent'] ?? []),
            interest: self::optionalString($payload['interest'] ?? null),
            whyInteresting: self::optionalString($payload['why_interesting'] ?? null),
            fatherSpecificReason: self::optionalString($payload['father_specific_reason'] ?? null),
            sustainabilityEvidence: self::optionalString($payload['sustainability_evidence'] ?? null),
            differentiated: (bool) ($payload['differentiated'] ?? false),
            isGiftCard: (bool) ($payload['is_gift_card'] ?? false),
            experienceType: self::optionalString($payload['experience_type'] ?? null),
            locationRestrictions: self::optionalString($payload['location_restrictions'] ?? null),
            validity: self::optionalString($payload['validity'] ?? null),
            redemptionMethod: self::optionalString($payload['redemption_method'] ?? null),
            affiliateViability: self::optionalString($payload['affiliate_viability'] ?? null),
            recipientSuitability: self::optionalString($payload['recipient_suitability'] ?? null),
            evidenceConfidence: self::evidenceConfidence($payload['evidence_confidence'] ?? 'medium'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function evidence(): array
    {
        return [
            'title' => $this->title,
            'merchant' => $this->merchant,
            'external_id' => $this->externalId,
            'price_amount' => $this->priceAmount,
            'price_currency' => $this->priceCurrency,
            'rating' => $this->rating,
            'review_count' => $this->reviewCount,
            'image_url' => $this->imageUrl,
            'features' => $this->features,
            'availability' => $this->availability,
            'source_url' => $this->sourceUrl,
            'evidence_confidence' => $this->evidenceConfidence,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'merchant' => $this->merchant,
            'source_url' => $this->sourceUrl,
            'target_gap' => $this->targetGap,
            'concept' => $this->concept,
            'external_id' => $this->externalId,
            'price_amount' => $this->priceAmount,
            'price_currency' => $this->priceCurrency,
            'rating' => $this->rating,
            'review_count' => $this->reviewCount,
            'image_url' => $this->imageUrl,
            'features' => $this->features,
            'availability' => $this->availability,
            'gift_potential' => $this->giftPotential,
            'gift_intents' => $this->giftIntents,
            'interest' => $this->interest,
            'why_interesting' => $this->whyInteresting,
            'father_specific_reason' => $this->fatherSpecificReason,
            'sustainability_evidence' => $this->sustainabilityEvidence,
            'differentiated' => $this->differentiated,
            'is_gift_card' => $this->isGiftCard,
            'experience_type' => $this->experienceType,
            'location_restrictions' => $this->locationRestrictions,
            'validity' => $this->validity,
            'redemption_method' => $this->redemptionMethod,
            'affiliate_viability' => $this->affiliateViability,
            'recipient_suitability' => $this->recipientSuitability,
            'evidence_confidence' => $this->evidenceConfidence,
        ];
    }

    public function identityKey(): string
    {
        if (is_string($this->externalId) && $this->externalId !== '') {
            return strtoupper($this->merchant).':'.strtoupper($this->externalId);
        }

        return strtolower(trim($this->sourceUrl));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Gap sourcing candidate {$key} is required.");
        }

        return trim($value);
    }

    private static function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function optionalFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(str_replace(',', '', $value))) {
            return (float) str_replace(',', '', $value);
        }

        return null;
    }

    private static function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }

        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                continue;
            }

            $items[] = trim($item);
        }

        return array_values(array_unique($items));
    }

    private static function giftPotential(mixed $value): string
    {
        $normalized = is_string($value) ? strtolower(trim($value)) : 'medium';

        return in_array($normalized, ['high', 'medium', 'low'], true) ? $normalized : 'medium';
    }

    private static function evidenceConfidence(mixed $value): string
    {
        $normalized = is_string($value) ? strtolower(trim($value)) : 'medium';

        return in_array($normalized, ['high', 'medium', 'low'], true) ? $normalized : 'medium';
    }
}
