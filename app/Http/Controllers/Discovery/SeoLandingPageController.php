<?php

namespace App\Http\Controllers\Discovery;

use App\Actions\Product\QueryPublishedProductsByFiltersAction;
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
    public function show(string $slug, QueryPublishedProductsByFiltersAction $queryProducts): RedirectResponse|View
    {
        if (in_array($slug, config('discovery.reserved_prefixes', []), true)) {
            abort(404);
        }

        $page = SeoLandingPage::query()
            ->where('slug', $slug)
            ->where('status', SeoLandingPageStatus::Published)
            ->with('interests')
            ->first();

        if ($page === null) {
            return $this->redirectFromOldSlug($slug);
        }

        try {
            $products = $queryProducts->execute([
                'occasion_id' => $page->occasion_id,
                'relationship_id' => $page->relationship_id,
                'recipient_type_id' => $page->recipient_type_id,
                'profession_id' => $page->profession_id,
                'gift_type_id' => $page->gift_type_id,
                'category_id' => $page->category_id,
                'budget_range_id' => $page->budget_range_id,
                'interest_ids' => $page->interests->pluck('id')->all(),
            ])
                ->with([
                    'images' => fn ($query) => $query
                        ->orderByDesc('is_primary')
                        ->orderBy('sort_order'),
                    'affiliateLinks' => fn ($query) => $query
                        ->active()
                        ->with('merchant')
                        ->orderByDesc('is_primary'),
                ])
                ->orderByDesc('published_at')
                ->paginate(12);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $pagination = PageMeta::paginatedCanonicals(
            $products,
            PageMeta::seoLandingPageCanonical($page),
        );

        return view('discovery.seo-landing-pages.show', [
            'page' => $page,
            'products' => $products,
            'seoTitle' => PageMeta::seoLandingPageTitle($page),
            'seoDescription' => PageMeta::seoLandingPageDescription($page),
            'seoCanonical' => $pagination['canonical'],
            'seoRobots' => PageMeta::seoLandingPageRobots($page),
            'seoPrev' => $pagination['prev'],
            'seoNext' => $pagination['next'],
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
