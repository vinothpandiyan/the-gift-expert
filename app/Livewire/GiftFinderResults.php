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

    private ?RecommendationSession $resolvedSession = null;

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;
    }

    public function render(): View
    {
        $session = $this->session();
        $results = $this->results();

        return view('livewire.gift-finder-results', [
            'results' => $results,
            'resultCount' => $results->count(),
            'summaryItems' => $this->summaryItems($session),
            'heading' => $this->heading($results->count()),
            'finderUrl' => DiscoveryUrl::finder(),
            'editUrl' => DiscoveryUrl::finderEdit($this->uuid),
            'giftIdeasUrl' => DiscoveryUrl::giftIdeas(),
        ])
            ->extends('layouts.public')
            ->title(PageMeta::finderResultsTitle())
            ->layoutData([
                'seoDescription' => PageMeta::finderResultsDescription(),
                'seoCanonical' => PageMeta::finderCanonical(),
                'seoRobots' => 'noindex, follow',
            ]);
    }

    public function session(): RecommendationSession
    {
        return $this->resolvedSession ??= RecommendationSession::query()
            ->where('uuid', $this->uuid)
            ->with([
                'occasion:id,name',
                'relationship:id,name',
                'recipientType:id,name',
                'profession:id,name',
                'giftType:id,name',
                'budgetRange:id,name',
                'interests:id,name',
            ])
            ->firstOrFail();
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
                $query->published()
                    ->whereHas('affiliateLinks', fn ($links) => $links->active());
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
                            'categories:id,slug',
                            'giftTypes:id,slug',
                        ]);
                },
            ])
            ->orderBy('rank')
            ->limit($topN)
            ->get()
            ->filter(fn (RecommendationResult $result) => $result->product !== null)
            ->values();
    }

    public function isGreatMatch(RecommendationResult $result, int $displayIndex): bool
    {
        $featuredBoost = (float) config('gift_recommendations.weights.featured_boost');

        return $displayIndex <= 3 && (float) $result->score > $featuredBoost;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function summaryItems(RecommendationSession $session): array
    {
        $items = [];

        if ($session->relationship !== null) {
            $items[] = ['label' => 'For', 'value' => $session->relationship->name];
        }

        if ($session->occasion !== null) {
            $items[] = ['label' => 'Occasion', 'value' => $session->occasion->name];
        }

        if ($session->interests->isNotEmpty()) {
            $items[] = [
                'label' => 'Interests',
                'value' => $session->interests->pluck('name')->implode(', '),
            ];
        }

        if ($session->budgetRange !== null) {
            $items[] = ['label' => 'Budget', 'value' => $session->budgetRange->name];
        }

        if ($session->recipientType !== null) {
            $items[] = ['label' => 'Recipient', 'value' => $session->recipientType->name];
        }

        if ($session->profession !== null) {
            $items[] = ['label' => 'Profession', 'value' => $session->profession->name];
        }

        if ($session->giftType !== null) {
            $items[] = ['label' => 'Gift type', 'value' => $session->giftType->name];
        }

        return $items;
    }

    private function heading(int $count): string
    {
        return match (true) {
            $count === 0 => "We couldn't find a strong match yet",
            $count === 1 => 'We found 1 gift idea',
            default => 'We found '.$count.' gift ideas',
        };
    }
}
