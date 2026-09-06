<?php

namespace App\Http\Controllers\Discovery;

use App\Actions\Discovery\QueryDiscoveryListingProductsAction;
use App\DiscoveryListing\DiscoveryListingContext;
use App\DiscoveryListing\DiscoveryListingQueryState;
use App\Enums\SeoLandingPageStatus;
use App\Http\Controllers\Controller;
use App\Models\SeoLandingPage;
use App\Models\SeoLandingPageRedirect;
use App\Support\DiscoveryUrl;
use App\Support\PageMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use InvalidArgumentException;

class SeoLandingPageController extends Controller
{
    public function show(
        string $slug,
        QueryDiscoveryListingProductsAction $queryListing,
    ): RedirectResponse|View {
        if (in_array($slug, config('discovery.reserved_prefixes', []), true)) {
            abort(404);
        }

        $page = SeoLandingPage::query()
            ->where('slug', $slug)
            ->where('status', SeoLandingPageStatus::Published)
            ->with([
                'interests',
                'relationship',
                'recipientType',
                'giftType',
                'occasion',
                'profession',
                'category',
            ])
            ->first();

        if ($page === null) {
            return $this->redirectFromOldSlug($slug);
        }

        $listingContext = DiscoveryListingContext::forSeoLandingPage($page);

        try {
            $state = DiscoveryListingQueryState::fromRequest(request())->scopedTo($listingContext);
            $perPage = max(1, (int) config('discovery_ranking.per_page', 12));
            $total = $state->hasUserFiltersOrSort()
                ? 0
                : $queryListing->count($listingContext, $state);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $seo = PageMeta::listingSeo(
            PageMeta::seoLandingPageCanonical($page),
            $total,
            $perPage,
            $page->is_indexable,
        );

        return view('discovery.seo-landing-pages.show', [
            'page' => $page,
            'listingContext' => $listingContext->toArray(),
            'seoTitle' => PageMeta::seoLandingPageTitle($page),
            'seoDescription' => PageMeta::seoLandingPageDescription($page),
            'seoCanonical' => $seo['canonical'],
            'seoRobots' => $seo['robots'],
            'seoPrev' => $seo['prev'],
            'seoNext' => $seo['next'],
            'breadcrumbs' => PageMeta::seoLandingPageBreadcrumbs($page),
        ]);
    }

    private function redirectFromOldSlug(string $slug): RedirectResponse
    {
        $redirect = SeoLandingPageRedirect::query()
            ->where('from_slug', $slug)
            ->first();

        if ($redirect === null) {
            abort(404);
        }

        $target = SeoLandingPage::query()
            ->where('slug', $redirect->to_slug)
            ->where('status', SeoLandingPageStatus::Published)
            ->first();

        if ($target === null) {
            abort(404);
        }

        return redirect(DiscoveryUrl::seoLandingPage($target->slug), 301);
    }
}
