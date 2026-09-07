<?php

namespace App\Http\Controllers\Discovery;

use App\Actions\Home\QueryFeaturedHomepageGiftsAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Http\Controllers\Controller;
use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Support\PageMeta;
use Illuminate\View\View;

class GiftIdeasController extends Controller
{
    public function index(QueryFeaturedHomepageGiftsAction $queryFeaturedGifts): View
    {
        $budgetRange = $this->activeBudgetFromRequest();

        if ($budgetRange instanceof BudgetRange) {
            $seo = PageMeta::listingSeo(PageMeta::giftIdeasCanonical(), 0);

            return view('discovery.gift-ideas.index', [
                'mode' => 'listing',
                'listingContext' => DiscoveryListingContext::forGiftIdeas()->toArray(),
                'budgetRange' => $budgetRange,
                'seoTitle' => PageMeta::giftIdeasTitle(),
                'seoDescription' => PageMeta::giftIdeasDescription(),
                'seoCanonical' => $seo['canonical'],
                'seoRobots' => $seo['robots'],
                'seoPrev' => $seo['prev'],
                'seoNext' => $seo['next'],
                'breadcrumbs' => PageMeta::giftIdeasBreadcrumbs(),
            ]);
        }

        $active = fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
        $seo = $this->hubSeo();

        return view('discovery.gift-ideas.index', [
            'mode' => 'hub',
            'listingContext' => null,
            'budgetRange' => null,
            'recipientTypes' => RecipientType::query()->tap($active)->get(),
            'relationships' => Relationship::query()->tap($active)->get(),
            'occasions' => Occasion::query()->tap($active)->get(),
            'interests' => Interest::query()->tap($active)->get(),
            'professions' => Profession::query()->tap($active)->get(),
            'giftTypes' => GiftType::query()->tap($active)->get(),
            'budgetRanges' => BudgetRange::query()->tap($active)->get(),
            'categories' => Category::query()
                ->whereNull('parent_id')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'featuredGifts' => $queryFeaturedGifts->execute(),
            'seoTitle' => PageMeta::giftIdeasTitle(),
            'seoDescription' => PageMeta::giftIdeasDescription(),
            'seoCanonical' => $seo['canonical'],
            'seoRobots' => $seo['robots'],
            'seoPrev' => $seo['prev'] ?? null,
            'seoNext' => $seo['next'] ?? null,
            'breadcrumbs' => PageMeta::giftIdeasBreadcrumbs(),
        ]);
    }

    private function activeBudgetFromRequest(): ?BudgetRange
    {
        $slug = DiscoveryListingQueryState::fromRequest(request())->budgetSlug;

        if ($slug === null) {
            return null;
        }

        return BudgetRange::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return array{canonical: string, robots: string, prev: ?string, next: ?string}
     */
    private function hubSeo(): array
    {
        if (DiscoveryListingQueryState::requestHasUserState()) {
            return PageMeta::listingSeo(PageMeta::giftIdeasCanonical(), 0);
        }

        return [
            'canonical' => PageMeta::giftIdeasCanonical(),
            'robots' => 'index, follow',
            'prev' => null,
            'next' => null,
        ];
    }
}
