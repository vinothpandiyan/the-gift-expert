<?php

namespace App\Actions\CuratedCatalog;

use App\CuratedCatalog\CuratedSourceListContext;
use App\Models\AffiliateLink;
use App\Models\CatalogProductSource;
use App\Models\Merchant;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class UpsertCatalogProductSourceAction
{
    public function __construct(
        private ResolveCatalogSourceListAction $resolveSourceList,
    ) {}

    public function execute(
        Merchant $merchant,
        AffiliateLink $affiliateLink,
        CuratedSourceListContext $context,
        ?int $intakeRunId = null,
        ?Carbon $seenAt = null,
        int $occurrenceIncrement = 1,
    ): CatalogProductSource {
        if (! $context->isPresent()) {
            throw new InvalidArgumentException('Source list identity is absent.');
        }

        $sourceList = $this->resolveSourceList->execute($merchant, $context, persist: true);
        $seenAt ??= now();
        $increment = max(1, $occurrenceIncrement);

        $existing = CatalogProductSource::query()
            ->where('affiliate_link_id', $affiliateLink->id)
            ->where('catalog_source_list_id', $sourceList->id)
            ->first();

        if ($existing instanceof CatalogProductSource) {
            $existing->last_seen_at = $seenAt;
            $existing->last_intake_run_id = $intakeRunId ?? $existing->last_intake_run_id;
            $existing->occurrence_count = (int) $existing->occurrence_count + $increment;
            $existing->save();

            return $existing->fresh();
        }

        return CatalogProductSource::query()->create([
            'affiliate_link_id' => $affiliateLink->id,
            'catalog_source_list_id' => $sourceList->id,
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
            'first_intake_run_id' => $intakeRunId,
            'last_intake_run_id' => $intakeRunId,
            'occurrence_count' => $increment,
        ]);
    }
}
