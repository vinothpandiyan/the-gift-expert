<?php

namespace App\DiscoveryListing;

/**
 * Public discovery filter option with a facet count.
 *
 * Taxonomy name/slug still come from the underlying model. `slug` is the
 * listing query value (category uses `full_path`). `parentId` is set for
 * hierarchical Categories so the listing UI can nest children without extra
 * queries. Parent selection is not recursive: counts and product queries use
 * the attached category ID only.
 */
final readonly class DiscoveryFilterOption
{
    public function __construct(
        public int $id,
        public string $label,
        public string $slug,
        public int $count,
        public bool $selected,
        public ?int $parentId = null,
    ) {}
}
