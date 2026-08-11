<?php

namespace App\Livewire;

use App\Models\RecommendationResult;
use App\Models\RecommendationSession;
use App\Support\DiscoveryUrl;
use App\Support\PageMeta;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class GiftFinderResults extends Component
{
    public string $uuid;

    public function mount(string $uuid): void
    {
        $session = RecommendationSession::query()
            ->where('uuid', $uuid)
            ->first();

        if ($session === null) {
            abort(404);
        }

        $this->uuid = $session->uuid;
    }

    public function render(): View
    {
        $results = $this->results();

        return view('livewire.gift-finder-results', [
            'results' => $results,
            'resultCount' => $results->count(),
            'finderUrl' => DiscoveryUrl::finder(),
        ])
            ->extends('layouts.public')
            ->title(PageMeta::finderResultsTitle())
            ->layoutData([
                'seoDescription' => PageMeta::finderResultsDescription(),
                'seoCanonical' => PageMeta::finderCanonical(),
                'seoRobots' => 'noindex, follow',
            ]);
    }

    /**
     * @return Collection<int, RecommendationResult>
     */
    public function results(): Collection
    {
        $topN = (int) config('gift_recommendations.top_n');

        return RecommendationResult::query()
            ->whereHas('recommendationSession', function ($query): void {
                $query->where('uuid', $this->uuid);
            })
            ->whereHas('product', function ($query): void {
                $query->published();
            })
            ->with([
                'product' => function ($query): void {
                    $query->published()
                        ->with([
                            'images' => fn ($images) => $images
                                ->orderByDesc('is_primary')
                                ->orderBy('sort_order'),
                            'affiliateLinks' => fn ($links) => $links
                                ->active()
                                ->with('merchant')
                                ->orderByDesc('is_primary'),
                        ]);
                },
            ])
            ->orderBy('rank')
            ->limit($topN)
            ->get()
            ->filter(fn (RecommendationResult $result) => $result->product !== null)
            ->values();
    }
}
