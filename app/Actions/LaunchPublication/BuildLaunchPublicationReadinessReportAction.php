<?php

namespace App\Actions\LaunchPublication;

use App\Actions\CatalogCuration\ResolveAcceptedCurationAuditRunAction;
use App\Actions\Product\AssessProductPublicationRequirementsAction;
use App\Enums\AffiliateLinkStatus;
use App\LaunchPublication\LaunchPublicationReadinessRow;
use App\Models\Product;
use App\Models\ProductCurationAudit;
use App\Models\ProductCurationAuditRun;
use Illuminate\Support\Collection;

class BuildLaunchPublicationReadinessReportAction
{
    public function __construct(
        private QueryLaunchPublicationCandidatesAction $queryCandidates,
        private AssessProductPublicationRequirementsAction $assessPublication,
        private ResolveAcceptedCurationAuditRunAction $resolveAcceptedRun,
    ) {}

    /**
     * @return array{
     *     retained_draft: int,
     *     publish_ready: int,
     *     blocked: int,
     *     blocker_counts: array<string, int>,
     *     blocker_groups: array<string, int>,
     *     rows: list<LaunchPublicationReadinessRow>
     * }
     */
    public function execute(?Collection $candidates = null): array
    {
        $candidates ??= $this->queryCandidates->execute();
        $audits = $this->auditsFor($candidates);

        $rows = [];
        $blockerCounts = [];

        foreach ($candidates as $product) {
            $assessment = $this->assessPublication->execute($product);
            $audit = $audits->get($product->id);
            $offer = $product->affiliateLinks
                ->firstWhere('is_primary', true)
                ?? $product->affiliateLinks->firstWhere('status', AffiliateLinkStatus::Active)
                ?? $product->affiliateLinks->first();

            $row = new LaunchPublicationReadinessRow(
                productId: (int) $product->id,
                title: (string) $product->name,
                humanDecision: $product->currentCurationDecision?->decision?->value,
                humanMerchandisingRole: $product->currentCurationDecision?->catalog_role?->value,
                giftScore: $audit?->gift_score,
                catalogValue: $audit?->catalog_value_score,
                priceAmount: $product->price_amount !== null ? (string) $product->price_amount : null,
                availability: $offer?->availability,
                ready: $assessment['error_codes'] === [],
                blockingCodes: $assessment['error_codes'],
                blockingReasons: $assessment['error_messages'],
                warningCodes: $assessment['warnings'],
                warningMessages: $assessment['warning_messages'],
                conceptKey: $audit?->concept_key,
                conceptLabel: $audit?->concept_label,
                giftIntents: array_values(array_filter(
                    (array) ($audit?->gift_intents ?? []),
                    fn (mixed $intent): bool => is_string($intent) && $intent !== '',
                )),
                budgetBand: is_array($audit?->catalog_context_snapshot)
                    ? ($audit->catalog_context_snapshot['price_band'] ?? null)
                    : null,
            );

            $rows[] = $row;

            foreach ($row->blockingCodes as $code) {
                $blockerCounts[$code] = ($blockerCounts[$code] ?? 0) + 1;
            }
        }

        ksort($blockerCounts);

        $groups = [];
        foreach ($blockerCounts as $code => $count) {
            $group = $this->group($code);
            $groups[$group] = ($groups[$group] ?? 0) + $count;
        }
        ksort($groups);

        $ready = collect($rows)->where('ready', true)->count();

        return [
            'retained_draft' => count($rows),
            'publish_ready' => $ready,
            'blocked' => count($rows) - $ready,
            'blocker_counts' => $blockerCounts,
            'blocker_groups' => $groups,
            'rows' => $rows,
        ];
    }

    /**
     * @param  Collection<int, Product>  $candidates
     * @return Collection<int, ProductCurationAudit>
     */
    private function auditsFor(Collection $candidates): Collection
    {
        $run = $this->resolveAcceptedRun->execute();

        if (! $run instanceof ProductCurationAuditRun || $candidates->isEmpty()) {
            return collect();
        }

        return ProductCurationAudit::query()
            ->where('run_id', $run->id)
            ->whereIn('product_id', $candidates->modelKeys())
            ->get()
            ->keyBy(fn (ProductCurationAudit $audit): int => (int) $audit->product_id);
    }

    private function group(string $code): string
    {
        return match ($code) {
            'missing_name' => 'editorial',
            'missing_slug' => 'seo',
            'no_image' => 'image',
            'no_active_affiliate_link' => 'affiliate',
            'missing_primary_category', 'invalid_primary_category', 'classification_not_publishable' => 'taxonomy',
            default => 'other',
        };
    }
}
