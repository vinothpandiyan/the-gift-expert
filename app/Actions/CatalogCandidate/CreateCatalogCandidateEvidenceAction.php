<?php

namespace App\Actions\CatalogCandidate;

use App\Enums\CatalogCandidateSourceType;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCatalogCandidateEvidenceAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(CatalogCandidate $candidate, array $attributes): CatalogCandidateEvidence
    {
        if ($candidate->trashed()) {
            throw ValidationException::withMessages([
                'catalog_candidate_id' => ['Evidence cannot be added to a deleted catalog candidate.'],
            ]);
        }

        $sourceType = $this->sourceType($attributes['source_type'] ?? null);
        $sourceUrl = $this->nullableTrimmedString($attributes['source_url'] ?? null);
        $summary = $this->nullableTrimmedString($attributes['summary'] ?? null);

        $this->assertSummaryIsNotHtmlDocument($summary);
        $this->assertUniqueSourceUrl($candidate, $sourceUrl);

        return DB::transaction(function () use ($candidate, $attributes, $sourceType, $sourceUrl, $summary): CatalogCandidateEvidence {
            return $candidate->evidence()->create([
                'source_type' => $sourceType,
                'source_name' => $this->nullableTrimmedString($attributes['source_name'] ?? null),
                'source_url' => $sourceUrl,
                'summary' => $summary,
                'observed_at' => $attributes['observed_at'] ?? now(),
                'metadata' => is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : null,
            ]);
        });
    }

    private function assertUniqueSourceUrl(CatalogCandidate $candidate, ?string $sourceUrl): void
    {
        if ($sourceUrl === null) {
            return;
        }

        $exists = $candidate->evidence()
            ->where('source_url', $sourceUrl)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'source_url' => ['This source URL is already attached to this catalog candidate.'],
            ]);
        }
    }

    private function assertSummaryIsNotHtmlDocument(?string $summary): void
    {
        if ($summary === null) {
            return;
        }

        if (preg_match('/<\/?(html|head|body|script|style)\b/i', $summary) === 1) {
            throw ValidationException::withMessages([
                'summary' => ['Evidence summaries must be concise notes, not full page HTML.'],
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
                'source_type' => ['A valid evidence source type is required.'],
            ]);
        }

        return $resolved;
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
