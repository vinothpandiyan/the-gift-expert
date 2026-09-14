<?php

namespace App\CatalogCuration;

readonly class HumanCurationReclassificationManifest
{
    /**
     * @param  array{from: ?array{id: int, name: string}, to: ?array{id: int, name: string}}|null  $category
     * @param  array<string, list<array{id: int, name: string}>>  $added
     * @param  array<string, list<array{id: int, name: string}>>  $removed
     */
    public function __construct(
        public int $productId,
        public int $decisionId,
        public ?array $category,
        public array $added,
        public array $removed,
        public ?string $classificationBefore,
        public ?string $classificationAfter,
        public int $actorId,
        public string $timestamp,
        public ?string $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'product_id' => $this->productId,
            'decision_id' => $this->decisionId,
            'classification_lifecycle' => [
                'before' => $this->classificationBefore,
                'after' => $this->classificationAfter,
            ],
            'actor' => $this->actorId,
            'timestamp' => $this->timestamp,
            'reason' => $this->reason,
        ];

        if ($this->category !== null) {
            $payload['category'] = $this->category;
        }

        foreach (['added' => $this->added, 'removed' => $this->removed] as $key => $dimensions) {
            foreach ($dimensions as $dimension => $items) {
                if ($items !== []) {
                    $payload[$dimension][$key] = $items;
                }
            }
        }

        return $payload;
    }
}
