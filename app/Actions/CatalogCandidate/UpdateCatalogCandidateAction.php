<?php

namespace App\Actions\CatalogCandidate;

use App\Enums\CatalogCandidatePriority;
use App\Enums\CatalogCandidateSourceType;
use App\Models\CatalogCandidate;
use App\Support\CatalogCandidateDuplicates;
use App\Support\CatalogCandidateTitleFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCatalogCandidateAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(
        CatalogCandidate $candidate,
        array $attributes,
        bool $allowSimilarTitle = false,
    ): CatalogCandidate {
        if ($candidate->trashed()) {
            throw ValidationException::withMessages([
                'title' => ['A deleted catalog candidate cannot be updated.'],
            ]);
        }

        $title = $this->requiredString($attributes['title'] ?? $candidate->title, 'title', 'A candidate title is required.');
        $sourceType = $this->sourceType($attributes['source_type'] ?? $candidate->source_type);
        $sourceName = $this->nullableTrimmedString($attributes['source_name'] ?? $candidate->source_name);
        $sourceUrl = $this->nullableTrimmedString($attributes['source_url'] ?? $candidate->source_url);
        $externalReference = $this->nullableTrimmedString($attributes['external_reference'] ?? $candidate->external_reference);
        $fingerprint = CatalogCandidateTitleFingerprint::from($title);

        $this->assertNoExternalReferenceDuplicate($sourceType, $sourceName, $externalReference, $candidate->id);
        $this->assertNoSourceUrlAndTitleDuplicate($sourceUrl, $fingerprint, $candidate->id);

        if (! $allowSimilarTitle) {
            $this->assertNoSimilarTitle($fingerprint, $candidate->id);
        }

        return DB::transaction(function () use (
            $candidate,
            $attributes,
            $title,
            $sourceType,
            $sourceName,
            $sourceUrl,
            $externalReference,
            $fingerprint,
        ): CatalogCandidate {
            $locked = CatalogCandidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            $locked->fill([
                'title' => $title,
                'title_fingerprint' => $fingerprint,
                'summary' => $this->nullableTrimmedString($attributes['summary'] ?? $locked->summary),
                'notes' => $this->nullableTrimmedString($attributes['notes'] ?? $locked->notes),
                'priority' => $this->priority($attributes['priority'] ?? $locked->priority),
                'source_type' => $sourceType,
                'source_name' => $sourceName,
                'source_url' => $sourceUrl,
                'external_reference' => $externalReference,
                'estimated_price_amount' => array_key_exists('estimated_price_amount', $attributes)
                    ? $attributes['estimated_price_amount']
                    : $locked->estimated_price_amount,
                'estimated_price_currency' => array_key_exists('estimated_price_currency', $attributes)
                    ? $this->nullableTrimmedString($attributes['estimated_price_currency'])
                    : $locked->estimated_price_currency,
                'discovered_at' => $attributes['discovered_at'] ?? $locked->discovered_at,
            ]);
            $locked->save();

            return $locked;
        });
    }

    private function assertNoExternalReferenceDuplicate(
        CatalogCandidateSourceType $sourceType,
        ?string $sourceName,
        ?string $externalReference,
        int $ignoreCandidateId,
    ): void {
        $existing = CatalogCandidateDuplicates::findExternalReferenceDuplicate(
            $sourceType,
            $sourceName,
            $externalReference,
            $ignoreCandidateId,
        );

        if ($existing instanceof CatalogCandidate) {
            throw ValidationException::withMessages([
                'external_reference' => [
                    "A catalog candidate with this external reference already exists (#{$existing->id}: {$existing->title}).",
                ],
            ]);
        }
    }

    private function assertNoSourceUrlAndTitleDuplicate(?string $sourceUrl, string $fingerprint, int $ignoreCandidateId): void
    {
        $existing = CatalogCandidateDuplicates::findSourceUrlAndTitleDuplicate(
            $sourceUrl,
            $fingerprint,
            $ignoreCandidateId,
        );

        if ($existing instanceof CatalogCandidate) {
            throw ValidationException::withMessages([
                'source_url' => [
                    "A catalog candidate with this source URL and title already exists (#{$existing->id}: {$existing->title}).",
                ],
            ]);
        }
    }

    private function assertNoSimilarTitle(string $fingerprint, int $ignoreCandidateId): void
    {
        $existing = CatalogCandidateDuplicates::findSimilarTitle($fingerprint, $ignoreCandidateId);

        if ($existing instanceof CatalogCandidate) {
            throw ValidationException::withMessages([
                'title' => [
                    "A catalog candidate with a similar title already exists (#{$existing->id}: {$existing->title}).",
                ],
            ]);
        }
    }

    private function sourceType(mixed $value): CatalogCandidateSourceType
    {
        if ($value instanceof CatalogCandidateSourceType) {
            return $value;
        }

        $resolved = is_string($value) ? CatalogCandidateSourceType::tryFrom($value) : null;

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'source_type' => ['A valid candidate source type is required.'],
            ]);
        }

        return $resolved;
    }

    private function priority(mixed $value): CatalogCandidatePriority
    {
        if ($value === null || $value === '') {
            return CatalogCandidatePriority::Normal;
        }

        if ($value instanceof CatalogCandidatePriority) {
            return $value;
        }

        $resolved = is_string($value) ? CatalogCandidatePriority::tryFrom($value) : null;

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'priority' => ['A valid candidate priority is required.'],
            ]);
        }

        return $resolved;
    }

    private function requiredString(mixed $value, string $key, string $message): string
    {
        $string = $this->nullableTrimmedString($value);

        if ($string === null) {
            throw ValidationException::withMessages([
                $key => [$message],
            ]);
        }

        return $string;
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
