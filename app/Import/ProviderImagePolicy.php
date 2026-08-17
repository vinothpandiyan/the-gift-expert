<?php

namespace App\Import;

readonly class ProviderImagePolicy
{
    public function __construct(
        public bool $storeImages,
        public bool $transformImages,
        public int $maxImages,
    ) {}

    public static function forKey(string $providerKey): self
    {
        $policy = config('import.providers.'.$providerKey.'.policy');

        if (! is_array($policy)) {
            return new self(false, false, 5);
        }

        return new self(
            storeImages: (bool) ($policy['store_images'] ?? false),
            transformImages: (bool) ($policy['transform_images'] ?? false),
            maxImages: array_key_exists('max_images', $policy)
                ? max(0, (int) $policy['max_images'])
                : 5,
        );
    }

    public function allowsLocalAcquisition(): bool
    {
        return $this->storeImages && $this->transformImages;
    }
}
