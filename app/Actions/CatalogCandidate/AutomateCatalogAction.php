<?php

namespace App\Actions\CatalogCandidate;

use App\Actions\Product\EvaluateAndPersistProductAutomationReadinessAction;
use App\Actions\Product\EvaluateProductAutomationReadinessAction;
use App\CatalogAutomation\CatalogAutomationOptions;
use App\CatalogAutomation\CatalogAutomationResult;
use App\CatalogCandidate\Discovery\CatalogCandidateResearchBrief;
use App\CatalogCandidate\Ingestion\CatalogCandidateIngestionResult;
use App\CommercialSourcing\CatalogCandidateSourcingItemOutcome;
use App\CommercialSourcing\CatalogCandidateSourcingResult;
use App\CommercialSourcing\ProductAutomationReadinessResult;
use App\CommercialSourcing\ProductPromotionPayload;
use App\Enums\CatalogAutomationRunStatus;
use App\Enums\CatalogAutomationStage;
use App\Enums\CatalogCandidateIngestionItemStatus;
use App\Enums\CatalogCandidateSourcingItemStatus;
use App\Enums\CatalogCandidateStatus;
use App\Enums\ProductAutomationReadiness;
use App\Models\CatalogAutomationRun;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateDiscoveryRun;
use App\Models\CatalogCandidateSourcingItem;
use App\Support\CatalogCandidateDuplicates;
use App\Support\CatalogCandidateTitleFingerprint;
use InvalidArgumentException;
use Throwable;

class AutomateCatalogAction
{
    public function __construct(
        private DiscoverCatalogCandidatesAction $discover,
        private SourceCatalogCandidatesAction $source,
        private PromoteCatalogCandidateSourcingItemAction $promoteSourcingItem,
        private EvaluateAndPersistProductAutomationReadinessAction $persistReadiness,
        private EvaluateProductAutomationReadinessAction $evaluateReadiness,
    ) {}

    public function execute(CatalogAutomationOptions $options): CatalogAutomationResult
    {
        $automationRun = $options->dryRun
            ? null
            : $this->startAutomationRun($options);

        try {
            $brief = CatalogCandidateResearchBrief::from(
                $options->brief,
                $options->market,
                $options->maxCandidates,
                $options->freshnessDays,
                $options->sourceCategories,
            );

            $this->markStage($automationRun, CatalogAutomationStage::Discovery);

            $discovery = $this->discover->execute(
                $brief,
                $options->dryRun,
                $options->createdByUserId,
            );

            $discoveryRunId = $this->resolveDiscoveryRunId($discovery, $options->dryRun);
            $this->linkDiscoveryRun($automationRun, $discoveryRunId);

            $resolution = $this->resolveCandidateIds($discovery, $options);
            $filtered = $this->filterCandidateIds($resolution['ids'], $options);

            $sourcingResult = null;
            $promoteOnlyOutcomes = [];
            $downstreamSkipped = false;
            $downstreamSkippedReason = null;

            if ($options->stopAfter->includesSourcing()) {
                if ($filtered['sourceIds'] === [] && $filtered['promoteOnlyItems'] === []) {
                    if ($options->dryRun && $resolution['ids'] === []) {
                        $downstreamSkipped = true;
                        $downstreamSkippedReason = 'Downstream stages skipped — no persisted candidate IDs. Run without --dry-run to execute the full pipeline.';
                    }

                    $this->markStage($automationRun, $this->terminalStageBeforeSourcing($options->stopAfter));
                } else {
                    $stage = $this->stageForSourcing($options->stopAfter);
                    $this->markStage($automationRun, $stage);

                    if ($filtered['sourceIds'] !== []) {
                        $sourcingResult = $this->source->execute(
                            market: $options->market,
                            limit: count($filtered['sourceIds']),
                            includeDiscovered: $options->includeDiscovered(),
                            dryRun: $options->dryRun,
                            createdByUserId: $options->createdByUserId,
                            enrich: $options->stopAfter->enrich(),
                            promote: $options->stopAfter->promote(),
                            candidateIds: $filtered['sourceIds'],
                        );

                        $this->linkSourcingRun($automationRun, $sourcingResult->run?->id);
                    }

                    if ($options->stopAfter->promote() && $filtered['promoteOnlyItems'] !== []) {
                        $promoteOnlyOutcomes = $this->promoteExistingItems(
                            $filtered['promoteOnlyItems'],
                            $options->dryRun,
                        );
                    }
                }
            } else {
                $this->markStage($automationRun, CatalogAutomationStage::Discovery);
            }

            $readinessCounts = ['ready' => 0, 'needs_review' => 0, 'blocked' => 0];

            if ($options->stopAfter->reEvaluateReadiness() && ! $downstreamSkipped) {
                $this->markStage($automationRun, CatalogAutomationStage::Readiness);
                $readinessCounts = $this->reEvaluateReadiness(
                    $sourcingResult,
                    $promoteOnlyOutcomes,
                    $options,
                );
            }

            $result = $this->buildResult(
                automationRun: $automationRun,
                discoveryRunId: $discoveryRunId,
                discovery: $discovery,
                resolution: $resolution,
                filtered: $filtered,
                sourcingResult: $sourcingResult,
                promoteOnlyOutcomes: $promoteOnlyOutcomes,
                readinessCounts: $readinessCounts,
                options: $options,
                downstreamSkipped: $downstreamSkipped,
                downstreamSkippedReason: $downstreamSkippedReason,
            );

            $this->completeAutomationRun($automationRun, $result, $discovery, $sourcingResult);

            return $result;
        } catch (Throwable $exception) {
            $this->failAutomationRun($automationRun, $exception->getMessage());

            throw $exception;
        }
    }

    private function startAutomationRun(CatalogAutomationOptions $options): CatalogAutomationRun
    {
        return CatalogAutomationRun::query()->create([
            'brief' => $options->brief,
            'market' => $options->market,
            'max_candidates' => $options->maxCandidates,
            'freshness_days' => $options->freshnessDays,
            'status' => CatalogAutomationRunStatus::Running,
            'current_stage' => CatalogAutomationStage::Discovery,
            'started_at' => now(),
            'created_by_user_id' => $options->createdByUserId,
        ]);
    }

    private function markStage(?CatalogAutomationRun $run, CatalogAutomationStage $stage): void
    {
        if ($run === null) {
            return;
        }

        $run->current_stage = $stage;
        $run->save();
    }

    private function resolveDiscoveryRunId(CatalogCandidateIngestionResult $discovery, bool $dryRun): ?int
    {
        if ($dryRun || $discovery->run === null) {
            return null;
        }

        return CatalogCandidateDiscoveryRun::query()
            ->where('catalog_candidate_ingestion_run_id', $discovery->run->id)
            ->value('id');
    }

    private function linkDiscoveryRun(?CatalogAutomationRun $run, ?int $discoveryRunId): void
    {
        if ($run === null || $discoveryRunId === null) {
            return;
        }

        $run->discovery_run_id = $discoveryRunId;
        $run->save();
    }

    private function linkSourcingRun(?CatalogAutomationRun $run, ?int $sourcingRunId): void
    {
        if ($run === null || $sourcingRunId === null) {
            return;
        }

        $run->sourcing_run_id = $sourcingRunId;
        $run->save();
    }

    /**
     * @return array{
     *     ids: list<int>,
     *     added: int,
     *     duplicate: int,
     *     existingContinued: int,
     * }
     */
    private function resolveCandidateIds(
        CatalogCandidateIngestionResult $discovery,
        CatalogAutomationOptions $options,
    ): array {
        $ids = [];
        $added = 0;
        $duplicate = 0;
        $existingContinued = 0;

        foreach ($discovery->outcomes as $outcome) {
            if ($outcome->status === CatalogCandidateIngestionItemStatus::Succeeded && $outcome->candidateId !== null) {
                $ids[] = $outcome->candidateId;
                $added++;

                continue;
            }

            if ($outcome->status !== CatalogCandidateIngestionItemStatus::Skipped) {
                continue;
            }

            if (! $options->continueExistingCandidates() || $outcome->title === null) {
                $duplicate++;

                continue;
            }

            $existing = CatalogCandidateDuplicates::findSimilarTitle(
                CatalogCandidateTitleFingerprint::from($outcome->title),
            );

            if (! $existing instanceof CatalogCandidate || ! $this->isEligibleStatus($existing->status)) {
                $duplicate++;

                continue;
            }

            $ids[] = $existing->id;
            $existingContinued++;
        }

        return [
            'ids' => array_values(array_unique($ids)),
            'added' => $added,
            'duplicate' => $duplicate,
            'existingContinued' => $existingContinued,
        ];
    }

    /**
     * @param  list<int>  $candidateIds
     * @return array{
     *     sourceIds: list<int>,
     *     promoteOnlyItems: list<CatalogCandidateSourcingItem>,
     *     alreadyPromotedSkipped: int,
     * }
     */
    private function filterCandidateIds(array $candidateIds, CatalogAutomationOptions $options): array
    {
        if ($candidateIds === []) {
            return [
                'sourceIds' => [],
                'promoteOnlyItems' => [],
                'alreadyPromotedSkipped' => 0,
            ];
        }

        $candidates = CatalogCandidate::query()
            ->with('latestSourcingItem')
            ->whereIn('id', $candidateIds)
            ->get()
            ->keyBy('id');

        $sourceIds = [];
        $promoteOnlyItems = [];
        $alreadyPromotedSkipped = 0;

        foreach ($candidateIds as $candidateId) {
            $candidate = $candidates->get($candidateId);

            if (! $candidate instanceof CatalogCandidate || ! $this->isEligibleStatus($candidate->status)) {
                continue;
            }

            $latest = $candidate->latestSourcingItem;

            if ($latest?->product_id !== null && ! $options->reSourceExisting()) {
                $alreadyPromotedSkipped++;

                continue;
            }

            if ($options->noEnrichExisting && $this->hasPromotableExistingItem($latest)) {
                $promoteOnlyItems[] = $latest;

                continue;
            }

            $sourceIds[] = $candidateId;
        }

        $sourceIds = $this->applyCandidateLimit($sourceIds, $options);

        if ($options->stopAfter->promote()) {
            $sourceIds = array_slice($sourceIds, 0, $options->maxProductsPromotedPerRun());
        }

        return [
            'sourceIds' => $sourceIds,
            'promoteOnlyItems' => $promoteOnlyItems,
            'alreadyPromotedSkipped' => $alreadyPromotedSkipped,
        ];
    }

    /**
     * @param  list<int>  $candidateIds
     * @return list<int>
     */
    private function applyCandidateLimit(array $candidateIds, CatalogAutomationOptions $options): array
    {
        if ($options->candidateLimit === null) {
            return $candidateIds;
        }

        return array_slice($candidateIds, 0, $options->candidateLimit);
    }

    private function isEligibleStatus(CatalogCandidateStatus $status): bool
    {
        return in_array($status, [CatalogCandidateStatus::Discovered, CatalogCandidateStatus::Approved], true);
    }

    private function hasPromotableExistingItem(?CatalogCandidateSourcingItem $item): bool
    {
        if (! $item instanceof CatalogCandidateSourcingItem) {
            return false;
        }

        if ($item->product_id !== null) {
            return false;
        }

        if (! is_array($item->selected_offer) || $item->selected_offer === []) {
            return false;
        }

        if (! is_array($item->enrichment) || $item->enrichment === []) {
            return false;
        }

        try {
            ProductPromotionPayload::fromAuditArray($item->enrichment);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<CatalogCandidateSourcingItem>  $items
     * @return list<CatalogCandidateSourcingItemOutcome>
     */
    private function promoteExistingItems(array $items, bool $dryRun): array
    {
        $outcomes = [];

        foreach ($items as $index => $item) {
            $item = $item->fresh(['candidate']);
            $candidate = $item->candidate;

            if (! $candidate instanceof CatalogCandidate) {
                continue;
            }

            $promotion = $this->promoteSourcingItem->execute($item, $dryRun);

            $outcomes[] = new CatalogCandidateSourcingItemOutcome(
                index: $index,
                candidateId: $candidate->id,
                candidateTitle: $candidate->title,
                status: $promotion->promoted
                    ? CatalogCandidateSourcingItemStatus::Succeeded
                    : CatalogCandidateSourcingItemStatus::Failed,
                selected: null,
                exceptionCodes: $promotion->exceptionCodes,
                rankBreakdown: [],
                error: $promotion->error,
                payload: null,
                productId: $promotion->productId,
                affiliateLinkId: $promotion->affiliateLinkId,
            );
        }

        return $outcomes;
    }

    /**
     * @param  list<CatalogCandidateSourcingItemOutcome>  $promoteOnlyOutcomes
     * @return array{ready: int, needs_review: int, blocked: int}
     */
    private function reEvaluateReadiness(
        ?CatalogCandidateSourcingResult $sourcingResult,
        array $promoteOnlyOutcomes,
        CatalogAutomationOptions $options,
    ): array {
        $counts = ['ready' => 0, 'needs_review' => 0, 'blocked' => 0];
        $itemIds = [];

        if ($sourcingResult?->run !== null) {
            $itemIds = CatalogCandidateSourcingItem::query()
                ->where('catalog_candidate_sourcing_run_id', $sourcingResult->run->id)
                ->pluck('id')
                ->all();
        }

        foreach ($promoteOnlyOutcomes as $outcome) {
            if ($outcome->productId !== null) {
                $latestItemId = CatalogCandidateSourcingItem::query()
                    ->where('catalog_candidate_id', $outcome->candidateId)
                    ->where('product_id', $outcome->productId)
                    ->orderByDesc('id')
                    ->value('id');

                if (is_int($latestItemId)) {
                    $itemIds[] = $latestItemId;
                }
            }
        }

        $itemIds = array_values(array_unique($itemIds));

        foreach ($itemIds as $itemId) {
            $item = CatalogCandidateSourcingItem::query()->find($itemId);

            if (! $item instanceof CatalogCandidateSourcingItem) {
                continue;
            }

            if ($options->dryRun) {
                $evaluation = $this->evaluateReadiness->execute($item);
            } else {
                $item = $this->persistReadiness->execute($item);
                $evaluation = new ProductAutomationReadinessResult(
                    readiness: $item->readiness,
                    exceptionCodes: is_array($item->exception_codes) ? $item->exception_codes : [],
                    warnings: [],
                );
            }

            $this->incrementReadinessCount($counts, $evaluation->readiness);
        }

        return $counts;
    }

    /**
     * @param  array{ready: int, needs_review: int, blocked: int}  $counts
     */
    private function incrementReadinessCount(array &$counts, ?ProductAutomationReadiness $readiness): void
    {
        match ($readiness) {
            ProductAutomationReadiness::Ready => $counts['ready']++,
            ProductAutomationReadiness::NeedsReview => $counts['needs_review']++,
            ProductAutomationReadiness::Blocked => $counts['blocked']++,
            default => null,
        };
    }

    private function stageForSourcing(CatalogAutomationStage $stopAfter): CatalogAutomationStage
    {
        return match ($stopAfter) {
            CatalogAutomationStage::Sourcing => CatalogAutomationStage::Sourcing,
            CatalogAutomationStage::Enrichment => CatalogAutomationStage::Enrichment,
            CatalogAutomationStage::Promotion, CatalogAutomationStage::Readiness => CatalogAutomationStage::Promotion,
            default => CatalogAutomationStage::Discovery,
        };
    }

    private function terminalStageBeforeSourcing(CatalogAutomationStage $stopAfter): CatalogAutomationStage
    {
        return match ($stopAfter) {
            CatalogAutomationStage::Sourcing => CatalogAutomationStage::Sourcing,
            CatalogAutomationStage::Enrichment => CatalogAutomationStage::Enrichment,
            CatalogAutomationStage::Promotion, CatalogAutomationStage::Readiness => CatalogAutomationStage::Promotion,
            default => CatalogAutomationStage::Discovery,
        };
    }

    /**
     * @param  array{
     *     ids: list<int>,
     *     added: int,
     *     duplicate: int,
     *     existingContinued: int,
     * }  $resolution
     * @param  array{
     *     sourceIds: list<int>,
     *     promoteOnlyItems: list<CatalogCandidateSourcingItem>,
     *     alreadyPromotedSkipped: int,
     * }  $filtered
     * @param  list<CatalogCandidateSourcingItemOutcome>  $promoteOnlyOutcomes
     * @param  array{ready: int, needs_review: int, blocked: int}  $readinessCounts
     */
    private function buildResult(
        ?CatalogAutomationRun $automationRun,
        ?int $discoveryRunId,
        CatalogCandidateIngestionResult $discovery,
        array $resolution,
        array $filtered,
        ?CatalogCandidateSourcingResult $sourcingResult,
        array $promoteOnlyOutcomes,
        array $readinessCounts,
        CatalogAutomationOptions $options,
        bool $downstreamSkipped,
        ?string $downstreamSkippedReason,
    ): CatalogAutomationResult {
        $sourced = 0;
        $enriched = 0;
        $promoted = 0;
        $failures = [];
        $productIds = [];

        if ($sourcingResult !== null) {
            foreach ($sourcingResult->outcomes as $outcome) {
                if ($outcome->status === CatalogCandidateSourcingItemStatus::Succeeded && $outcome->selected !== null) {
                    $sourced++;
                }

                if ($outcome->payload !== null) {
                    $enriched++;
                }

                if ($outcome->productId !== null) {
                    $promoted++;
                    $productIds[] = $outcome->productId;
                }

                if ($outcome->status === CatalogCandidateSourcingItemStatus::Failed) {
                    $failures[] = [
                        'candidateId' => $outcome->candidateId,
                        'code' => $outcome->exceptionCodes[0] ?? 'failed',
                        'message' => $outcome->error ?? 'Sourcing failed.',
                    ];
                }
            }
        }

        foreach ($promoteOnlyOutcomes as $outcome) {
            if ($outcome->productId !== null) {
                $promoted++;
                $productIds[] = $outcome->productId;
            }

            if ($outcome->status === CatalogCandidateSourcingItemStatus::Failed) {
                $failures[] = [
                    'candidateId' => $outcome->candidateId,
                    'code' => $outcome->exceptionCodes[0] ?? 'promotion_failed',
                    'message' => $outcome->error ?? 'Promotion failed.',
                ];
            }
        }

        $candidatesProposed = $discovery->itemsTotal;

        if ($discoveryRunId !== null) {
            $proposed = CatalogCandidateDiscoveryRun::query()->whereKey($discoveryRunId)->value('candidates_proposed');

            if (is_int($proposed)) {
                $candidatesProposed = $proposed;
            }
        } elseif ($discovery->itemsTotal > 0) {
            $candidatesProposed = $discovery->itemsTotal;
        }

        $stoppedAfter = $downstreamSkipped
            ? CatalogAutomationStage::Discovery
            : $options->stopAfter;

        return new CatalogAutomationResult(
            automationRunId: $automationRun?->id,
            discoveryRunId: $discoveryRunId,
            sourcingRunId: $sourcingResult?->run?->id,
            queriesCount: count($discovery->queries),
            candidatesProposed: $candidatesProposed,
            candidatesAdded: $resolution['added'],
            candidatesDuplicate: $resolution['duplicate'],
            existingCandidatesContinued: $resolution['existingContinued'],
            alreadyPromotedSkipped: $filtered['alreadyPromotedSkipped'],
            candidatesSourced: $sourced,
            candidatesEnriched: $enriched,
            candidatesPromoted: $promoted,
            ready: $readinessCounts['ready'],
            needsReview: $readinessCounts['needs_review'],
            blocked: $readinessCounts['blocked'],
            failures: $failures,
            productIds: array_values(array_unique($productIds)),
            dryRun: $options->dryRun,
            stoppedAfter: $stoppedAfter,
            downstreamSkipped: $downstreamSkipped,
            downstreamSkippedReason: $downstreamSkippedReason,
        );
    }

    private function completeAutomationRun(
        ?CatalogAutomationRun $run,
        CatalogAutomationResult $result,
        CatalogCandidateIngestionResult $discovery,
        ?CatalogCandidateSourcingResult $sourcingResult,
    ): void {
        if ($run === null) {
            return;
        }

        $hasErrors = $discovery->itemsFailed > 0
            || ($sourcingResult !== null && $sourcingResult->itemsFailed > 0)
            || $result->failures !== [];

        $run->status = $hasErrors
            ? CatalogAutomationRunStatus::CompletedWithErrors
            : CatalogAutomationRunStatus::Completed;
        $run->current_stage = $result->stoppedAfter;
        $run->finished_at = now();
        $run->counts = [
            'queries_count' => $result->queriesCount,
            'candidates_proposed' => $result->candidatesProposed,
            'candidates_added' => $result->candidatesAdded,
            'candidates_duplicate' => $result->candidatesDuplicate,
            'existing_candidates_continued' => $result->existingCandidatesContinued,
            'already_promoted_skipped' => $result->alreadyPromotedSkipped,
            'candidates_sourced' => $result->candidatesSourced,
            'candidates_enriched' => $result->candidatesEnriched,
            'candidates_promoted' => $result->candidatesPromoted,
            'ready' => $result->ready,
            'needs_review' => $result->needsReview,
            'blocked' => $result->blocked,
        ];
        $run->save();
    }

    private function failAutomationRun(?CatalogAutomationRun $run, string $error): void
    {
        if ($run === null) {
            return;
        }

        $run->status = CatalogAutomationRunStatus::Failed;
        $run->error = $error;
        $run->finished_at = now();
        $run->save();
    }
}
