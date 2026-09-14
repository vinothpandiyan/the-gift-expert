<?php

namespace App\LaunchPublication;

use App\Enums\LaunchPublicationResult;

readonly class LaunchPublicationAttempt
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $productId,
        public LaunchPublicationResult $result,
        public string $reason,
        public ?string $title = null,
        public ?string $humanDecision = null,
        public ?string $previousStatus = null,
        public ?string $newStatus = null,
        public ?string $publishedAt = null,
        public ?string $actor = null,
        public array $warnings = [],
    ) {}

    /**
     * @param  list<string>  $warnings
     */
    public static function published(
        int $productId,
        string $title,
        ?string $humanDecision,
        string $previousStatus,
        string $newStatus,
        ?string $publishedAt,
        string $actor,
        array $warnings = [],
    ): self {
        return new self(
            productId: $productId,
            result: LaunchPublicationResult::Published,
            reason: 'published',
            title: $title,
            humanDecision: $humanDecision,
            previousStatus: $previousStatus,
            newStatus: $newStatus,
            publishedAt: $publishedAt,
            actor: $actor,
            warnings: $warnings,
        );
    }

    public static function skipped(
        int $productId,
        string $reason,
        ?string $title = null,
        ?string $humanDecision = null,
        ?string $previousStatus = null,
    ): self {
        return new self(
            productId: $productId,
            result: LaunchPublicationResult::Skipped,
            reason: $reason,
            title: $title,
            humanDecision: $humanDecision,
            previousStatus: $previousStatus,
            newStatus: $previousStatus,
        );
    }

    public static function failed(
        int $productId,
        string $reason,
        ?string $title = null,
        ?string $humanDecision = null,
        ?string $previousStatus = null,
    ): self {
        return new self(
            productId: $productId,
            result: LaunchPublicationResult::Failed,
            reason: $reason,
            title: $title,
            humanDecision: $humanDecision,
            previousStatus: $previousStatus,
            newStatus: $previousStatus,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'result' => $this->result->value,
            'reason' => $this->reason,
            'title' => $this->title,
            'human_decision' => $this->humanDecision,
            'previous_status' => $this->previousStatus,
            'new_status' => $this->newStatus,
            'published_at' => $this->publishedAt,
            'actor' => $this->actor,
            'warnings' => $this->warnings,
        ];
    }
}
