<?php

namespace App\Filament\Resources\Gifts\Pages;

use App\Enums\TaxonomyClassificationStatus;
use App\Filament\Resources\Gifts\GiftResource;
use App\Models\Product;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListGifts extends ListRecords
{
    protected static string $resource = GiftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $counts = Product::query()
            ->selectRaw('taxonomy_classification_status, COUNT(*) as aggregate')
            ->groupBy('taxonomy_classification_status')
            ->pluck('aggregate', 'taxonomy_classification_status');

        $countFor = function (TaxonomyClassificationStatus ...$statuses) use ($counts): int {
            $total = 0;

            foreach ($statuses as $status) {
                $total += (int) ($counts[$status->value] ?? 0);
            }

            return $total;
        };

        return [
            'all' => Tab::make('All')
                ->badge((int) $counts->sum()),
            'review' => Tab::make('Needs Review')
                ->badge($countFor(TaxonomyClassificationStatus::Review))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('taxonomy_classification_status', TaxonomyClassificationStatus::Review)),
            'failed' => Tab::make('Failed')
                ->badge($countFor(TaxonomyClassificationStatus::Failed))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('taxonomy_classification_status', TaxonomyClassificationStatus::Failed)),
            'ai_accepted' => Tab::make('AI Accepted')
                ->badge($countFor(TaxonomyClassificationStatus::AiAccepted))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('taxonomy_classification_status', TaxonomyClassificationStatus::AiAccepted)),
            'human_approved' => Tab::make('Human Approved')
                ->badge($countFor(TaxonomyClassificationStatus::HumanApproved))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('taxonomy_classification_status', TaxonomyClassificationStatus::HumanApproved)),
            'human_overridden' => Tab::make('Human Overridden')
                ->badge($countFor(TaxonomyClassificationStatus::HumanOverridden))
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('taxonomy_classification_status', TaxonomyClassificationStatus::HumanOverridden)),
            'unclassified' => Tab::make('Unclassified')
                ->badge($countFor(TaxonomyClassificationStatus::None, TaxonomyClassificationStatus::AiProposed))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('taxonomy_classification_status', [
                        TaxonomyClassificationStatus::None,
                        TaxonomyClassificationStatus::AiProposed,
                    ])),
        ];
    }
}
