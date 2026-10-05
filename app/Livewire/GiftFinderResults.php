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

        $bestResults = $results->filter(fn (RecommendationResult $result) => $result->matchTier() === RecommendationResult::TIER_BEST)->values();
        $broaderResults = $results->reject(fn (RecommendationResult $result) => $result->matchTier() === RecommendationResult::TIER_BEST)->values();

        return view('livewire.gift-finder-results', [
            'results' => $results,
            'resultCount' => $results->count(),
            'bestResults' => $bestResults,
            'broaderResults' => $broaderResults,
            'hasBroaderResults' => $bestResults->isNotEmpty() && $broaderResults->isNotEmpty(),
            'bestHeading' => $this->bestHeading($session, $bestResults->count()),
            'broaderHeading' => $this->broaderHeading($session),
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
                'recipientGender:id,name',
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

        return $displayIndex <= 3
            && $result->matchTier() === RecommendationResult::TIER_BEST
            && (float) $result->score > $featuredBoost;
    }

    private function bestHeading(RecommendationSession $session, int $count): string
    {
        $noun = $count === 1 ? 'great match' : 'great matches';

        return $session->relationship !== null
            ? "{$count} {$noun} for {$session->relationship->name}"
            : "{$count} {$noun} for you";
    }

    private function broaderHeading(RecommendationSession $session): string
    {
        return $session->occasion !== null
            ? "More {$session->occasion->name} gifts they may like"
            : 'More gifts they may like';
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

        $items[] = ['label' => 'Budget', 'value' => $session->budgetRange?->name ?? 'Any budget'];

        if ($session->recipientType !== null) {
            $items[] = ['label' => 'Recipient', 'value' => $session->recipientType->name];
        }

        if ($session->recipientGender !== null) {
            $items[] = ['label' => 'Gender', 'value' => $session->recipientGender->name];
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
