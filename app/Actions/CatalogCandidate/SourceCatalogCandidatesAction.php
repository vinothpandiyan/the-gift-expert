<?php

namespace App\Actions\CatalogCandidate;

use App\CommercialSourcing\CatalogCandidateSourcingItemOutcome;
use App\CommercialSourcing\CatalogCandidateSourcingResult;
use App\CommercialSourcing\CommercialEnrichmentException;
use App\CommercialSourcing\CommercialOfferSearchException;
use App\CommercialSourcing\ExtractCommercialExternalProductId;
use App\CommercialSourcing\SourcedMerchantOffer;
use App\Enums\CatalogCandidateSourcingItemStatus;
use App\Enums\CatalogCandidateSourcingRunStatus;
use App\Enums\CatalogCandidateStatus;
use App\Enums\CommercialExternalIdSource;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateSourcingItem;
use App\Models\CatalogCandidateSourcingRun;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

class SourceCatalogCandidatesAction
{
    public function __construct(
        private SearchCommercialOffersAction $searchCommercialOffers,
        private SelectCommercialOfferAction $selectCommercialOffer,
        private ExtractCommercialExternalProductId $extractExternalProductId,
        private EnrichAndClassifyCommercialOfferAction $enrichAndClassify,
    ) {}

    public function execute(
        string $market = 'IN',
        ?int $candidateId = null,
        int $limit = 20,
        bool $includeDiscovered = false,
        bool $dryRun = false,
        ?int $createdByUserId = null,
        bool $enrich = false,
        ?int $enrichItemId = null,
    ): CatalogCandidateSourcingResult {
        $market = strtoupper(trim($market));

        if ($market === '' || strlen($market) !== 2) {
            throw new InvalidArgumentException('A two-letter market code is required.');
        }

        if ($enrichItemId !== null) {
            return $this->enrichExistingItem($enrichItemId, $dryRun, $market);
        }

        $limit = min(100, max(1, $limit));
        $candidates = $this->eligibleCandidates($candidateId, $limit, $includeDiscovered);
        $outcomes = [];
        $succeeded = 0;
        $skipped = 0;
        $failed = 0;
        $run = null;

        if (! $dryRun) {
            $run = CatalogCandidateSourcingRun::query()->create([
                'status' => CatalogCandidateSourcingRunStatus::Running,
                'market' => $market,
                'started_at' => now(),
                'created_by_user_id' => $createdByUserId,
            ]);
        }

        foreach ($candidates as $index => $candidate) {
            try {
                $outcome = $this->sourceCandidate($candidate, $market, $index, $includeDiscovered);

                if ($enrich && $outcome->status === CatalogCandidateSourcingItemStatus::Succeeded && $outcome->selected !== null) {
                    $outcome = $this->attachEnrichment($candidate, $outcome);
                }
            } catch (CommercialOfferSearchException|InvalidArgumentException|Throwable $exception) {
                $outcome = new CatalogCandidateSourcingItemOutcome(
                    index: $index,
                    candidateId: $candidate->id,
                    candidateTitle: $candidate->title,
                    status: CatalogCandidateSourcingItemStatus::Failed,
                    selected: null,
                    exceptionCodes: [],
                    rankBreakdown: [],
                    error: $exception->getMessage(),
                );
            }

            $outcomes[] = $outcome;

            match ($outcome->status) {
                CatalogCandidateSourcingItemStatus::Succeeded => $succeeded++,
                CatalogCandidateSourcingItemStatus::Skipped => $skipped++,
                CatalogCandidateSourcingItemStatus::Failed => $failed++,
            };

            if ($run !== null) {
                $payload = $outcome->payload;
                $item = CatalogCandidateSourcingItem::query()->create([
                    'catalog_candidate_sourcing_run_id' => $run->id,
                    'catalog_candidate_id' => $candidate->id,
                    'merchant_id' => $outcome->selected?->merchantId,
                    'selected_offer' => $outcome->selected?->toAuditArray($outcome->rankBreakdown),
                    'enrichment' => $payload?->toAuditArray(),
                    'product_id' => null,
                    'affiliate_link_id' => null,
                    'status' => $outcome->status,
                    'readiness' => null,
                    'exception_codes' => $outcome->exceptionCodes === [] ? null : $outcome->exceptionCodes,
                    'error' => $outcome->error,
                ]);

                if ($payload !== null) {
                    $stamped = $payload->withSourcingItemId($item->id);
                    $item->update(['enrichment' => $stamped->toAuditArray()]);
                    $outcomes[array_key_last($outcomes)] = new CatalogCandidateSourcingItemOutcome(
                        index: $outcome->index,
                        candidateId: $outcome->candidateId,
                        candidateTitle: $outcome->candidateTitle,
                        status: $outcome->status,
                        selected: $outcome->selected,
                        exceptionCodes: $outcome->exceptionCodes,
                        rankBreakdown: $outcome->rankBreakdown,
                        error: $outcome->error,
                        payload: $stamped,
                    );
                }
            }
        }

        $total = $candidates->count();

        if ($run !== null) {
            $run->items_total = $total;
            $run->items_succeeded = $succeeded;
            $run->items_skipped = $skipped;
            $run->items_failed = $failed;
            $run->finished_at = now();
            $run->status = $failed > 0
                ? CatalogCandidateSourcingRunStatus::CompletedWithErrors
                : CatalogCandidateSourcingRunStatus::Completed;
            $run->save();
        }

        return new CatalogCandidateSourcingResult(
            run: $run,
            market: $market,
            itemsTotal: $total,
            itemsSucceeded: $succeeded,
            itemsSkipped: $skipped,
            itemsFailed: $failed,
            outcomes: $outcomes,
            dryRun: $dryRun,
        );
    }

    private function sourceCandidate(
        CatalogCandidate $candidate,
        string $market,
        int $index,
        bool $includeDiscovered,
    ): CatalogCandidateSourcingItemOutcome {
        if ($candidate->status === CatalogCandidateStatus::Rejected) {
            return $this->skipped($candidate, $index, ['rejected'], 'Rejected catalog candidates are not sourced.');
        }

        if ($candidate->status === CatalogCandidateStatus::UnderReview) {
            return $this->skipped($candidate, $index, ['under_review'], 'Catalog candidates under review are not sourced.');
        }

        if ($candidate->status === CatalogCandidateStatus::Discovered && ! $includeDiscovered) {
            return $this->skipped($candidate, $index, ['not_approved'], 'Discovered catalog candidates require --include-discovered.');
        }

        $search = $this->searchCommercialOffers->execute($candidate, $market);
        $selection = $this->selectCommercialOffer->execute($candidate, $search['offers']);

        if ($selection->selected === null) {
            return $this->skipped($candidate, $index, ['no_offer'], 'No commercial offer was found on approved merchant domains.');
        }

        $offer = $selection->selected;
        $codes = $this->exceptionCodes($offer);

        return new CatalogCandidateSourcingItemOutcome(
            index: $index,
            candidateId: $candidate->id,
            candidateTitle: $candidate->title,
            status: CatalogCandidateSourcingItemStatus::Succeeded,
            selected: $offer,
            exceptionCodes: $codes,
            rankBreakdown: $selection->rankBreakdown,
            error: $codes === [] ? null : implode(', ', $codes),
        );
    }

    /**
     * @param  list<string>  $codes
     */
    private function skipped(
        CatalogCandidate $candidate,
        int $index,
        array $codes,
        string $error,
    ): CatalogCandidateSourcingItemOutcome {
        return new CatalogCandidateSourcingItemOutcome(
            index: $index,
            candidateId: $candidate->id,
            candidateTitle: $candidate->title,
            status: CatalogCandidateSourcingItemStatus::Skipped,
            selected: null,
            exceptionCodes: $codes,
            rankBreakdown: [],
            error: $error,
        );
    }

    /**
     * @return list<string>
     */
    private function exceptionCodes(SourcedMerchantOffer $offer): array
    {
        $codes = [];
        $identity = $this->extractExternalProductId->execute($offer->merchantSlug, $offer->sourceUrl);

        if ($identity->unstableIdentity) {
            $codes[] = 'unstable_identity';
        } elseif ($offer->externalIdSource === CommercialExternalIdSource::None) {
            $strategy = (string) (config('commercial_sourcing.merchants.'.$offer->merchantSlug.'.external_id_strategy') ?? 'manual');

            $codes[] = $strategy === 'manual' ? 'manual_identity' : 'missing_external_id';
        }

        return $codes;
    }

    /**
     * @return Collection<int, CatalogCandidate>
     */
    private function eligibleCandidates(?int $candidateId, int $limit, bool $includeDiscovered): Collection
    {
        $query = CatalogCandidate::query()->orderBy('id');

        if ($candidateId !== null) {
            $candidate = $query->whereKey($candidateId)->first();

            if ($candidate === null) {
                throw new InvalidArgumentException("Catalog candidate [{$candidateId}] was not found.");
            }

            return collect([$candidate]);
        }

        $statuses = [CatalogCandidateStatus::Approved];

        if ($includeDiscovered) {
            $statuses[] = CatalogCandidateStatus::Discovered;
        }

        return $query
            ->whereIn('status', $statuses)
            ->limit($limit)
            ->get();
    }

    private function attachEnrichment(
        CatalogCandidate $candidate,
        CatalogCandidateSourcingItemOutcome $outcome,
        ?int $sourcingItemId = null,
    ): CatalogCandidateSourcingItemOutcome {
        if ($outcome->selected === null) {
            return $outcome;
        }

        try {
            $payload = $this->enrichAndClassify->execute(
                $candidate,
                $outcome->selected,
                $sourcingItemId,
            );
        } catch (CommercialEnrichmentException|InvalidArgumentException $exception) {
            return new CatalogCandidateSourcingItemOutcome(
                index: $outcome->index,
                candidateId: $outcome->candidateId,
                candidateTitle: $outcome->candidateTitle,
                status: CatalogCandidateSourcingItemStatus::Failed,
                selected: $outcome->selected,
                exceptionCodes: $outcome->exceptionCodes,
                rankBreakdown: $outcome->rankBreakdown,
                error: $exception->getMessage(),
            );
        }

        $codes = array_values(array_unique(array_merge($outcome->exceptionCodes, $payload->exceptionCodes)));

        return new CatalogCandidateSourcingItemOutcome(
            index: $outcome->index,
            candidateId: $outcome->candidateId,
            candidateTitle: $outcome->candidateTitle,
            status: CatalogCandidateSourcingItemStatus::Succeeded,
            selected: $outcome->selected,
            exceptionCodes: $codes,
            rankBreakdown: $outcome->rankBreakdown,
            error: $codes === [] ? null : implode(', ', $codes),
            payload: $payload,
        );
    }

    private function enrichExistingItem(int $enrichItemId, bool $dryRun, string $market): CatalogCandidateSourcingResult
    {
        $item = CatalogCandidateSourcingItem::query()->with('run')->find($enrichItemId);

        if ($item === null) {
            throw new InvalidArgumentException("Sourcing item [{$enrichItemId}] was not found.");
        }

        if (! is_array($item->selected_offer) || $item->selected_offer === []) {
            throw new InvalidArgumentException("Sourcing item [{$enrichItemId}] has no selected offer to enrich.");
        }

        $candidate = CatalogCandidate::query()->find($item->catalog_candidate_id);

        if (! $candidate instanceof CatalogCandidate) {
            throw new InvalidArgumentException("Sourcing item [{$enrichItemId}] has no catalog candidate.");
        }

        $offer = SourcedMerchantOffer::fromAuditArray($item->selected_offer);
        $base = new CatalogCandidateSourcingItemOutcome(
            index: 0,
            candidateId: $candidate->id,
            candidateTitle: $candidate->title,
            status: CatalogCandidateSourcingItemStatus::Succeeded,
            selected: $offer,
            exceptionCodes: is_array($item->exception_codes) ? $item->exception_codes : [],
            rankBreakdown: is_array($item->selected_offer['rank_breakdown'] ?? null)
                ? $item->selected_offer['rank_breakdown']
                : [],
            error: null,
        );
        $outcome = $this->attachEnrichment($candidate, $base, $item->id);

        if (! $dryRun) {
            $item->status = $outcome->status;
            $item->exception_codes = $outcome->exceptionCodes === [] ? null : $outcome->exceptionCodes;
            $item->error = $outcome->error;
            $item->enrichment = $outcome->payload?->toAuditArray();
            $item->product_id = null;
            $item->affiliate_link_id = null;
            $item->save();
        }

        $succeeded = $outcome->status === CatalogCandidateSourcingItemStatus::Succeeded ? 1 : 0;
        $failed = $outcome->status === CatalogCandidateSourcingItemStatus::Failed ? 1 : 0;

        return new CatalogCandidateSourcingResult(
            run: $dryRun ? null : $item->run,
            market: $market,
            itemsTotal: 1,
            itemsSucceeded: $succeeded,
            itemsSkipped: 0,
            itemsFailed: $failed,
            outcomes: [$outcome],
            dryRun: $dryRun,
        );
    }
}
