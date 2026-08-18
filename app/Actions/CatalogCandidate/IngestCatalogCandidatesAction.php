<?php

namespace App\Actions\CatalogCandidate;

use App\CatalogCandidate\Ingestion\CatalogCandidateIngestionItemOutcome;
use App\CatalogCandidate\Ingestion\CatalogCandidateIngestionResult;
use App\CatalogCandidate\Ingestion\IngestedCatalogCandidate;
use App\CatalogCandidate\Ingestion\IngestionRowError;
use App\Enums\CatalogCandidateIngestionFormat;
use App\Enums\CatalogCandidateIngestionItemStatus;
use App\Enums\CatalogCandidateIngestionRunStatus;
use App\Enums\CatalogCandidateSourceType;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateIngestionRun;
use App\Support\CatalogCandidateDuplicates;
use App\Support\CatalogCandidateTitleFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class IngestCatalogCandidatesAction
{
    /**
     * @param  iterable<IngestedCatalogCandidate|IngestionRowError>  $rows
     */
    public function execute(
        iterable $rows,
        CatalogCandidateIngestionFormat $format,
        string $sourceName,
        bool $dryRun = false,
        ?int $createdByUserId = null,
    ): CatalogCandidateIngestionResult {
        $rows = iterator_to_array($rows, false);

        if ($dryRun) {
            return $this->dryRun($rows);
        }

        $run = CatalogCandidateIngestionRun::query()->create([
            'format' => $format,
            'source_name' => $sourceName,
            'status' => CatalogCandidateIngestionRunStatus::Completed,
            'started_at' => now(),
            'items_total' => count($rows),
            'created_by_user_id' => $createdByUserId,
        ]);

        $outcomes = [];

        try {
            foreach ($rows as $row) {
                $outcomes[] = $this->processLiveItem($run, $row, $createdByUserId);
            }
        } catch (Throwable $exception) {
            $run->status = CatalogCandidateIngestionRunStatus::Failed;
            $run->error = $exception->getMessage();
            $run->finished_at = now();
            $run->save();

            throw $exception;
        }

        $run->items_succeeded = count(array_filter(
            $outcomes,
            fn (CatalogCandidateIngestionItemOutcome $outcome): bool => $outcome->status === CatalogCandidateIngestionItemStatus::Succeeded,
        ));
        $run->items_skipped = count(array_filter(
            $outcomes,
            fn (CatalogCandidateIngestionItemOutcome $outcome): bool => $outcome->status === CatalogCandidateIngestionItemStatus::Skipped,
        ));
        $run->items_failed = count(array_filter(
            $outcomes,
            fn (CatalogCandidateIngestionItemOutcome $outcome): bool => $outcome->status === CatalogCandidateIngestionItemStatus::Failed,
        ));
        $run->status = $run->items_failed > 0
            ? CatalogCandidateIngestionRunStatus::CompletedWithErrors
            : CatalogCandidateIngestionRunStatus::Completed;
        $run->finished_at = now();
        $run->save();

        return new CatalogCandidateIngestionResult(
            itemsTotal: $run->items_total,
            itemsSucceeded: $run->items_succeeded,
            itemsSkipped: $run->items_skipped,
            itemsFailed: $run->items_failed,
            outcomes: $outcomes,
            run: $run->fresh(['items']),
        );
    }

    /**
     * @param  list<IngestedCatalogCandidate|IngestionRowError>  $rows
     */
    private function dryRun(array $rows): CatalogCandidateIngestionResult
    {
        $seenFingerprints = [];
        $seenUrlFingerprints = [];
        $seenReferences = [];
        $outcomes = [];

        foreach ($rows as $row) {
            if ($row instanceof IngestionRowError) {
                $outcomes[] = $this->outcomeFromRowError($row);

                continue;
            }

            $evidenceError = $this->evidenceError($row);

            if ($evidenceError !== null) {
                $outcomes[] = new CatalogCandidateIngestionItemOutcome(
                    $row->index,
                    $row->title,
                    CatalogCandidateIngestionItemStatus::Failed,
                    $evidenceError,
                );

                continue;
            }

            $duplicate = $this->duplicateReason($row, $seenFingerprints, $seenUrlFingerprints, $seenReferences);

            if ($duplicate !== null) {
                $outcomes[] = new CatalogCandidateIngestionItemOutcome(
                    $row->index,
                    $row->title,
                    CatalogCandidateIngestionItemStatus::Skipped,
                    $duplicate,
                );

                continue;
            }

            $this->remember($row, $seenFingerprints, $seenUrlFingerprints, $seenReferences);

            $outcomes[] = new CatalogCandidateIngestionItemOutcome(
                $row->index,
                $row->title,
                CatalogCandidateIngestionItemStatus::Succeeded,
                null,
            );
        }

        return $this->resultFromOutcomes($outcomes);
    }

    private function processLiveItem(
        CatalogCandidateIngestionRun $run,
        IngestedCatalogCandidate|IngestionRowError $row,
        ?int $createdByUserId,
    ): CatalogCandidateIngestionItemOutcome {
        if ($row instanceof IngestionRowError) {
            $outcome = $this->outcomeFromRowError($row);
            $this->recordItem($run, $outcome, $row->sourcePayload);

            return $outcome;
        }

        $evidenceError = $this->evidenceError($row);

        if ($evidenceError !== null) {
            $outcome = new CatalogCandidateIngestionItemOutcome(
                $row->index,
                $row->title,
                CatalogCandidateIngestionItemStatus::Failed,
                $evidenceError,
            );
            $this->recordItem($run, $outcome, $row->sourcePayload);

            return $outcome;
        }

        try {
            $candidate = DB::transaction(function () use ($row, $createdByUserId): CatalogCandidate {
                $candidate = app(CreateCatalogCandidateAction::class)->execute(
                    $row->toCandidateAttributes($createdByUserId),
                    $row->allowSimilarTitle,
                );

                foreach ($row->evidence as $evidence) {
                    app(CreateCatalogCandidateEvidenceAction::class)->execute($candidate, $evidence->toAttributes());
                }

                return $candidate;
            });

            $outcome = new CatalogCandidateIngestionItemOutcome(
                $row->index,
                $row->title,
                CatalogCandidateIngestionItemStatus::Succeeded,
                null,
                $candidate->id,
            );
            $this->recordItem($run, $outcome, $row->sourcePayload);

            return $outcome;
        } catch (ValidationException $exception) {
            $outcome = new CatalogCandidateIngestionItemOutcome(
                $row->index,
                $row->title,
                $this->isDuplicate($exception)
                    ? CatalogCandidateIngestionItemStatus::Skipped
                    : CatalogCandidateIngestionItemStatus::Failed,
                collect($exception->errors())->flatten()->first() ?? 'The candidate could not be ingested.',
            );
            $this->recordItem($run, $outcome, $row->sourcePayload);

            return $outcome;
        } catch (Throwable $exception) {
            $outcome = new CatalogCandidateIngestionItemOutcome(
                $row->index,
                $row->title,
                CatalogCandidateIngestionItemStatus::Failed,
                $exception->getMessage(),
            );
            $this->recordItem($run, $outcome, $row->sourcePayload);

            return $outcome;
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function recordItem(
        CatalogCandidateIngestionRun $run,
        CatalogCandidateIngestionItemOutcome $outcome,
        ?array $payload,
    ): void {
        $run->items()->create([
            'item_index' => $outcome->index,
            'title' => $outcome->title,
            'catalog_candidate_id' => $outcome->candidateId,
            'status' => $outcome->status,
            'error' => $outcome->error,
            'source_payload' => $payload,
        ]);
    }

    private function outcomeFromRowError(IngestionRowError $row): CatalogCandidateIngestionItemOutcome
    {
        return new CatalogCandidateIngestionItemOutcome(
            $row->index,
            $row->title,
            $row->skip ? CatalogCandidateIngestionItemStatus::Skipped : CatalogCandidateIngestionItemStatus::Failed,
            $row->message,
        );
    }

    /**
     * @param  list<CatalogCandidateIngestionItemOutcome>  $outcomes
     */
    private function resultFromOutcomes(array $outcomes): CatalogCandidateIngestionResult
    {
        $succeeded = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($outcomes as $outcome) {
            match ($outcome->status) {
                CatalogCandidateIngestionItemStatus::Succeeded => $succeeded++,
                CatalogCandidateIngestionItemStatus::Skipped => $skipped++,
                CatalogCandidateIngestionItemStatus::Failed => $failed++,
            };
        }

        return new CatalogCandidateIngestionResult(
            itemsTotal: count($outcomes),
            itemsSucceeded: $succeeded,
            itemsSkipped: $skipped,
            itemsFailed: $failed,
            outcomes: $outcomes,
        );
    }

    /**
     * @param  array<string, true>  $seenFingerprints
     * @param  array<string, true>  $seenUrlFingerprints
     * @param  array<string, true>  $seenReferences
     */
    private function duplicateReason(
        IngestedCatalogCandidate $row,
        array $seenFingerprints,
        array $seenUrlFingerprints,
        array $seenReferences,
    ): ?string {
        $fingerprint = CatalogCandidateTitleFingerprint::from($row->title);

        if ($row->externalReference !== null) {
            $referenceKey = $this->referenceKey($row->sourceType, $row->sourceName, $row->externalReference);

            if (isset($seenReferences[$referenceKey])
                || CatalogCandidateDuplicates::findExternalReferenceDuplicate(
                    $row->sourceType,
                    $row->sourceName,
                    $row->externalReference,
                ) instanceof CatalogCandidate) {
                return 'A catalog candidate with this external reference already exists.';
            }
        }

        if ($row->sourceUrl !== null) {
            $urlKey = $row->sourceUrl.'|'.$fingerprint;

            if (isset($seenUrlFingerprints[$urlKey])
                || CatalogCandidateDuplicates::findSourceUrlAndTitleDuplicate($row->sourceUrl, $fingerprint) instanceof CatalogCandidate) {
                return 'A catalog candidate with this source URL and title already exists.';
            }
        }

        if (! $row->allowSimilarTitle) {
            if (isset($seenFingerprints[$fingerprint])
                || CatalogCandidateDuplicates::findSimilarTitle($fingerprint) instanceof CatalogCandidate) {
                return 'A catalog candidate with a similar title already exists.';
            }
        }

        return null;
    }

    /**
     * @param  array<string, true>  $seenFingerprints
     * @param  array<string, true>  $seenUrlFingerprints
     * @param  array<string, true>  $seenReferences
     */
    private function remember(
        IngestedCatalogCandidate $row,
        array &$seenFingerprints,
        array &$seenUrlFingerprints,
        array &$seenReferences,
    ): void {
        $fingerprint = CatalogCandidateTitleFingerprint::from($row->title);
        $seenFingerprints[$fingerprint] = true;

        if ($row->sourceUrl !== null) {
            $seenUrlFingerprints[$row->sourceUrl.'|'.$fingerprint] = true;
        }

        if ($row->externalReference !== null) {
            $seenReferences[$this->referenceKey($row->sourceType, $row->sourceName, $row->externalReference)] = true;
        }
    }

    private function referenceKey(
        CatalogCandidateSourceType $sourceType,
        ?string $sourceName,
        string $externalReference,
    ): string {
        $normalizedName = $sourceName === null ? '' : mb_strtolower($sourceName, 'UTF-8');

        return $sourceType->value.'|'.$normalizedName.'|'.$externalReference;
    }

    private function evidenceError(IngestedCatalogCandidate $row): ?string
    {
        $urls = [];

        foreach ($row->evidence as $evidence) {
            if ($evidence->summary !== null && preg_match('/<\/?(html|head|body|script|style)\b/i', $evidence->summary) === 1) {
                return 'Evidence summaries must be concise notes, not full page HTML.';
            }

            if ($evidence->sourceUrl !== null) {
                if (isset($urls[$evidence->sourceUrl])) {
                    return 'This source URL is already attached to this catalog candidate.';
                }

                $urls[$evidence->sourceUrl] = true;
            }
        }

        return null;
    }

    private function isDuplicate(ValidationException $exception): bool
    {
        foreach (['external_reference', 'source_url', 'title'] as $key) {
            foreach ($exception->errors()[$key] ?? [] as $message) {
                if (str_contains(strtolower((string) $message), 'already exists')) {
                    return true;
                }
            }
        }

        return false;
    }
}
