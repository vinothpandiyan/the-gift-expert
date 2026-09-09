<?php

namespace App\CuratedCatalog;

readonly class CuratedSourceListContext
{
    public function __construct(
        public ?string $name,
        public ?string $externalListId,
        public ?string $sourceUrl,
        public ?string $clientListKind,
        public bool $malformed = false,
        public ?string $malformedReason = null,
    ) {}

    public function isPresent(): bool
    {
        return $this->name !== null || $this->externalListId !== null;
    }

    public function displayName(): ?string
    {
        return $this->name ?? $this->externalListId;
    }

    public function identityKey(): ?string
    {
        if ($this->externalListId !== null) {
            return 'id:'.$this->externalListId;
        }

        $normalized = CatalogSourceListName::normalize($this->name);

        return $normalized !== null ? 'name:'.$normalized : null;
    }
}
