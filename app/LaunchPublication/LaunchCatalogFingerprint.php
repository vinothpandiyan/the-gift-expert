<?php

namespace App\LaunchPublication;

readonly class LaunchCatalogFingerprint
{
    /**
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $tables
     */
    public function __construct(
        public array $counts,
        public array $tables,
        public string $hash,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'counts' => $this->counts,
            'hash' => $this->hash,
        ];
    }
}
