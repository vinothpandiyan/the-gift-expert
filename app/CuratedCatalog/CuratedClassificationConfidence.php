<?php

namespace App\CuratedCatalog;

readonly class CuratedClassificationConfidence
{
    public function __construct(
        public ?float $primaryCategory = null,
        public ?float $relationships = null,
        public ?float $recipientTypes = null,
        public ?float $occasions = null,
        public ?float $interests = null,
        public ?float $professions = null,
        public ?float $giftTypes = null,
        public bool $structurallyValid = true,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $values = [
            'primary_category' => null,
            'relationships' => null,
            'recipient_types' => null,
            'occasions' => null,
            'interests' => null,
            'professions' => null,
            'gift_types' => null,
        ];
        $valid = true;

        foreach ($values as $key => $_) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }

            $parsed = self::parseScore($raw[$key]);

            if ($parsed === false) {
                $valid = false;
                $values[$key] = null;

                continue;
            }

            $values[$key] = $parsed;
        }

        return new self(
            primaryCategory: $values['primary_category'],
            relationships: $values['relationships'],
            recipientTypes: $values['recipient_types'],
            occasions: $values['occasions'],
            interests: $values['interests'],
            professions: $values['professions'],
            giftTypes: $values['gift_types'],
            structurallyValid: $valid,
        );
    }

    /**
     * @return array<string, float|null>
     */
    public function toArray(): array
    {
        return [
            'primary_category' => $this->primaryCategory,
            'relationships' => $this->relationships,
            'recipient_types' => $this->recipientTypes,
            'occasions' => $this->occasions,
            'interests' => $this->interests,
            'professions' => $this->professions,
            'gift_types' => $this->giftTypes,
        ];
    }

    private static function parseScore(mixed $value): float|false|null
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return false;
        }

        $score = round((float) $value, 2);

        if ($score < 0.0 || $score > 1.0) {
            return false;
        }

        return $score;
    }
}
