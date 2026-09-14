<?php

namespace App\Filament\Resources\HumanCuration\Pages;

use App\Actions\CatalogCuration\QueryHumanCurationQueueAction;
use App\Actions\CatalogCuration\ResolveCurationReviewProgressAction;
use App\Filament\Resources\HumanCuration\HumanCurationResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListHumanCuration extends ListRecords
{
    protected static string $resource = HumanCurationResource::class;

    protected static ?string $title = 'Human Curation Workbench';

    public function getSubheading(): string|Htmlable|null
    {
        $progress = app(ResolveCurationReviewProgressAction::class)->execute();
        $run = app(QueryHumanCurationQueueAction::class)->acceptedRun();

        $decisionSummary = collect($progress->decisionCounts)
            ->filter()
            ->map(fn (int $count, string $decision): string => str($decision)->replace('_', ' ')->headline().': '.$count)
            ->implode(' · ');

        $featureShare = number_format($progress->featureShare * 100, 1);

        return trim(implode(' ', [
            'Accepted audit: '.($run?->id ?? 'none').'.',
            "Mandatory review: {$progress->mandatoryReview}.",
            "Decided: {$progress->decided}.",
            "Deferred: {$progress->deferred}.",
            "Remaining: {$progress->remaining}.",
            "FEATURE: {$progress->featureCount} of {$progress->keepFamilyCount} retained ({$featureShare}%).",
            $progress->featureDensityWarning ? 'FEATURE density is high and should be reviewed.' : '',
            $decisionSummary !== '' ? $decisionSummary.'.' : '',
        ]));
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'needs_review';
    }

    public function getTabs(): array
    {
        $counts = app(QueryHumanCurationQueueAction::class)->viewCounts();

        return [
            'needs_review' => Tab::make('Needs Review')->badge($counts['needs_review'] ?? 0)->badgeColor('warning'),
            'p0' => Tab::make('P0 Integrity')->badge($counts['p0'] ?? 0)->badgeColor('danger'),
            'p1' => Tab::make('P1 Redundancy')->badge($counts['p1'] ?? 0)->badgeColor('warning'),
            'p2' => Tab::make('P2 Taxonomy')->badge($counts['p2'] ?? 0)->badgeColor('info'),
            'p3' => Tab::make('P3 Quality')->badge($counts['p3'] ?? 0),
            'p3_a' => Tab::make('P3-A Gift Score')->badge($counts['p3_a'] ?? 0),
            'p3_b' => Tab::make('P3-B Catalog Value')->badge($counts['p3_b'] ?? 0),
            'p3_c' => Tab::make('P3-C Evidence')->badge($counts['p3_c'] ?? 0),
            'p3_d' => Tab::make('P3-D Confidence')->badge($counts['p3_d'] ?? 0),
            'p3_e' => Tab::make('P3-E Remaining')->badge($counts['p3_e'] ?? 0),
            'p4' => Tab::make('P4 Advisory')->badge($counts['p4'] ?? 0),
            'concept_clusters' => Tab::make('Concept Clusters')->badge($counts['concept_clusters'] ?? 0),
            'low_gift_score' => Tab::make('Low Gift Score')->badge($counts['low_gift_score'] ?? 0),
            'low_catalog_value' => Tab::make('Low Catalog Value')->badge($counts['low_catalog_value'] ?? 0),
            'missing_evidence' => Tab::make('Missing Evidence')->badge($counts['missing_evidence'] ?? 0),
            'reviewed' => Tab::make('Reviewed')->badge($counts['reviewed'] ?? 0)->badgeColor('success'),
            'deferred' => Tab::make('Deferred')->badge($counts['deferred'] ?? 0),
            'all' => Tab::make('All')->badge($counts['all'] ?? 0),
        ];
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();
        $criteria = HumanCurationResource::criteriaFromTableState(
            $this->tableFilters,
            $this->activeTab,
            $this->getTableSortColumn(),
            $this->getTableSortDirection(),
        );
        $ids = app(QueryHumanCurationQueueAction::class)->productIds($criteria);

        if ($ids === []) {
            return $query->whereRaw('0 = 1');
        }

        $order = collect($ids)
            ->map(fn (int $id, int $index): string => 'WHEN '.$id.' THEN '.$index)
            ->implode(' ');

        return $query
            ->whereIn($query->getModel()->getQualifiedKeyName(), $ids)
            ->orderByRaw('CASE '.$query->getModel()->getQualifiedKeyName().' '.$order.' END');
    }
}
