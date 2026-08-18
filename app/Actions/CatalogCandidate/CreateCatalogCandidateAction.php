<?php

namespace App\Actions\CatalogCandidate;

use App\Enums\CatalogCandidatePriority;
use App\Enums\CatalogCandidateSourceType;
use App\Enums\CatalogCandidateStatus;
use App\Models\CatalogCandidate;
use App\Support\CatalogCandidateDuplicates;
use App\Support\CatalogCandidateTitleFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCatalogCandidateAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes, bool $allowSimilarTitle = false): CatalogCandidate
    {
        $title = $this->requiredString($attributes['title'] ?? null, 'title', 'A candidate title is required.');
        $sourceType = $this->sourceType($attributes['source_type'] ?? null);
        $this->assertInitialStatus($attributes['status'] ?? null);

        $sourceName = $this->nullableTrimmedString($attributes['source_name'] ?? null);
        $sourceUrl = $this->nullableTrimmedString($attributes['source_url'] ?? null);
        $externalReference = $this->nullableTrimmedString($attributes['external_reference'] ?? null);
        $fingerprint = CatalogCandidateTitleFingerprint::from($title);

        $this->assertNoExternalReferenceDuplicate($sourceType, $sourceName, $externalReference);
        $this->assertNoSourceUrlAndTitleDuplicate($sourceUrl, $fingerprint);

        if (! $allowSimilarTitle) {
            $this->assertNoSimilarTitle($fingerprint);
        }

        $evidenceAttributes = $attributes['evidence'] ?? null;

        return DB::transaction(function () use (
            $attributes,
            $title,
            $sourceType,
            $sourceName,
            $sourceUrl,
            $externalReference,
            $fingerprint,
            $evidenceAttributes,
        ): CatalogCandidate {
            $candidate = CatalogCandidate::query()->create([
                'title' => $title,
                'title_fingerprint' => $fingerprint,
                'summary' => $this->nullableTrimmedString($attributes['summary'] ?? null),
                'notes' => $this->nullableTrimmedString($attributes['notes'] ?? null),
                'status' => CatalogCandidateStatus::Discovered,
                'priority' => $this->priority($attributes['priority'] ?? null),
                'source_type' => $sourceType,
                'source_name' => $sourceName,
                'source_url' => $sourceUrl,
                'external_reference' => $externalReference,
                'estimated_price_amount' => $attributes['estimated_price_amount'] ?? null,
                'estimated_price_currency' => $this->nullableTrimmedString($attributes['estimated_price_currency'] ?? null),
                'discovered_at' => $attributes['discovered_at'] ?? now(),
                'created_by_user_id' => $attributes['created_by_user_id'] ?? null,
            ]);

            if (is_array($evidenceAttributes) && $evidenceAttributes !== []) {
                app(CreateCatalogCandidateEvidenceAction::class)->execute($candidate, $evidenceAttributes);
            }

            return $candidate->fresh(['evidence']) ?? $candidate;
        });
    }

    private function assertInitialStatus(mixed $status): void
    {
        if ($status === null || $status === '') {
            return;
        }

        $resolved = $status instanceof CatalogCandidateStatus
            ? $status
            : CatalogCandidateStatus::tryFrom((string) $status);

        if ($resolved !== CatalogCandidateStatus::Discovered) {
            throw ValidationException::withMessages([
                'status' => ['New catalog candidates must start as discovered.'],
            ]);
        }
    }

    private function assertNoExternalReferenceDuplicate(
        CatalogCandidateSourceType $sourceType,
        ?string $sourceName,
        ?string $externalReference,
    ): void {
        if ($externalReference === null) {
            return;
        }

        $existing = CatalogCandidateDuplicates::findExternalReferenceDuplicate(
            $sourceType,
            $sourceName,
            $externalReference,
        );

        if ($existing instanceof CatalogCandidate) {
            throw ValidationException::withMessages([
                'external_reference' => [
                    "A catalog candidate with this external reference already exists (#{$existing->id}: {$existing->title}).",
                ],
            ]);
        }
    }

    private function assertNoSourceUrlAndTitleDuplicate(?string $sourceUrl, string $fingerprint): void
    {
        if ($sourceUrl === null) {
            return;
        }

        $existing = CatalogCandidateDuplicates::findSourceUrlAndTitleDuplicate($sourceUrl, $fingerprint);

        if ($existing instanceof CatalogCandidate) {
            throw ValidationException::withMessages([
                'source_url' => [
                    "A catalog candidate with this source URL and title already exists (#{$existing->id}: {$existing->title}).",
                ],
            ]);
        }
    }

    private function assertNoSimilarTitle(string $fingerprint): void
    {
        $existing = CatalogCandidateDuplicates::findSimilarTitle($fingerprint);

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
