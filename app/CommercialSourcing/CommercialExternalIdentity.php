<?php

namespace App\CommercialSourcing;

use App\Enums\CommercialExternalIdSource;

readonly class CommercialExternalIdentity
{
    public function __construct(
        public ?string $externalProductId,
        public CommercialExternalIdSource $source,
        public bool $unstableIdentity,
    ) {}
}
