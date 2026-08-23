<?php

namespace App\CuratedCatalog;

readonly class CuratedImageAcquisitionOutcome
{
    public const STATUS_ACQUIRED = 'image_acquired';

    public const STATUS_ALREADY_PRESENT = 'image_already_present';

    public const STATUS_MISSING_SOURCE = 'image_missing_source';

    public const STATUS_FAILED = 'image_acquisition_failed';

    public function __construct(
        public string $status,
        public ?string $detail = null,
    ) {}

    public function warningCode(): ?string
    {
        return match ($this->status) {
            self::STATUS_MISSING_SOURCE, self::STATUS_FAILED => $this->status,
            default => null,
        };
    }

    public function infoCode(): ?string
    {
        return match ($this->status) {
            self::STATUS_ACQUIRED, self::STATUS_ALREADY_PRESENT => $this->status,
            default => null,
        };
    }

    public function isWarning(): bool
    {
        return $this->warningCode() !== null;
    }

    /**
     * @return list<string>
     */
    public function auditCodes(): array
    {
        $codes = [];

        if ($this->infoCode() !== null) {
            $codes[] = $this->infoCode();
        }

        if ($this->warningCode() !== null) {
            $codes[] = $this->warningCode();
        }

        return $codes;
    }
}
