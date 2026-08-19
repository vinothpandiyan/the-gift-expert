<?php

namespace App\CatalogCoverage;

readonly class AutomationReadinessSummary
{
    public function __construct(
        public int $ready,
        public int $needsReview,
        public int $blocked,
        public int $unevaluated,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'needs_review' => $this->needsReview,
            'blocked' => $this->blocked,
            'unevaluated' => $this->unevaluated,
        ];
    }
}
