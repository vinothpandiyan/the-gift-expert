<?php

namespace App\PublicationReadiness;

readonly class PublicationReadinessRemediationResult
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $blockersAfter
     */
    public function __construct(
        public PublicationReadinessDiagnosis $diagnosis,
        public array $before,
        public array $after,
        public bool $publishReady,
        public array $blockersAfter,
        public bool $published,
        public bool $archived,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product' => $this->diagnosis->productId,
            'title' => $this->diagnosis->title,
            'blocker' => $this->diagnosis->blockers,
            'diagnosis' => $this->diagnosis->code->letter(),
            'before' => $this->before,
            'approved_remediation' => $this->diagnosis->remediation->value,
            'after' => $this->after,
            'publication_readiness' => $this->publishReady ? 'READY' : 'BLOCKED',
            'blockers_after' => $this->blockersAfter,
            'published' => $this->published,
            'archived' => $this->archived,
        ];
    }
}
