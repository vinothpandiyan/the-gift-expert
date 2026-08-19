<?php

namespace App\Filament\Resources\CatalogCandidates\Pages;

use App\Enums\ProductAutomationReadiness;
use App\Enums\ProductStatus;
use App\Filament\Resources\CatalogCandidates\CatalogCandidateResource;
use App\Models\CatalogCandidateSourcingItem;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCatalogCandidates extends ListRecords
{
    protected static string $resource = CatalogCandidateResource::class;

    public function getSubheading(): ?string
    {
        $ready = CatalogCandidateSourcingItem::query()
            ->where('readiness', ProductAutomationReadiness::Ready)
            ->whereHas('product', fn ($query) => $query->where('status', ProductStatus::Draft))
            ->count();

        $needsReview = CatalogCandidateSourcingItem::query()
            ->where('readiness', ProductAutomationReadiness::NeedsReview)
            ->where(function ($query): void {
                $query->whereNull('product_id')
                    ->orWhereHas('product', fn ($productQuery) => $productQuery->where('status', ProductStatus::Draft));
            })
            ->count();

        $blocked = CatalogCandidateSourcingItem::query()
            ->where('readiness', ProductAutomationReadiness::Blocked)
            ->where(function ($query): void {
                $query->whereNull('product_id')
                    ->orWhereHas('product', fn ($productQuery) => $productQuery->where('status', ProductStatus::Draft));
            })
            ->count();

        return "Needs review: {$needsReview} · Blocked: {$blocked} · Ready to publish: {$ready}";
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
