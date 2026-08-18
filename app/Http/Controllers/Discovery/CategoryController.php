<?php

namespace App\Http\Controllers\Discovery;

use App\Actions\SeoLandingPage\QueryDiscoverableSeoLandingPagesAction;
use App\Enums\SeoLandingPageStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryPathRedirect;
use App\Support\DiscoveryUrl;
use App\Support\PageMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CategoryController extends Controller
{
    private const MAX_REDIRECT_HOPS = 3;

    public function show(string $full_path, QueryDiscoverableSeoLandingPagesAction $queryLandingPages): RedirectResponse|View
    {
        $path = $this->resolvedCategoryPath($full_path);

        $category = Category::query()
            ->where('full_path', $path)
            ->where('is_active', true)
            ->with(['parent', 'canonicalSeoLandingPage'])
            ->first();

        if ($category === null) {
            abort(404);
        }

        $landingPage = $category->publishedCanonicalSeoLandingPage();

        if ($landingPage !== null) {
            return redirect(DiscoveryUrl::seoLandingPage($landingPage->slug), 301);
        }

        if ($path !== $full_path) {
            return redirect(DiscoveryUrl::giftIdeasCategory($category->full_path), 301);
        }

        $children = $category->children()
            ->where('is_active', true)
            ->whereDoesntHave('canonicalSeoLandingPage', function ($query): void {
                $query->where('status', SeoLandingPageStatus::Published);
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $products = $category->products()
            ->published()
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

        $pagination = PageMeta::paginatedCanonicals(
            $products,
            PageMeta::categoryCanonical($category),
        );

        return view('discovery.categories.show', [
            'category' => $category,
            'children' => $children,
            'relatedLandingPages' => $queryLandingPages->forCategory($category),
            'products' => $products,
            'seoTitle' => PageMeta::categoryTitle($category),
            'seoDescription' => PageMeta::categoryDescription($category),
            'seoCanonical' => $pagination['canonical'],
            'seoRobots' => 'index, follow',
            'seoPrev' => $pagination['prev'],
            'seoNext' => $pagination['next'],
            'breadcrumbs' => PageMeta::categoryBreadcrumbs($category),
            'giftBrowseContext' => 'category:'.$category->full_path,
        ]);
    }

    private function resolvedCategoryPath(string $fullPath): string
    {
        $path = $fullPath;

        for ($hop = 0; $hop < self::MAX_REDIRECT_HOPS; $hop++) {
            $redirect = CategoryPathRedirect::query()
                ->where('from_path', $path)
                ->first();

            if ($redirect === null) {
                break;
            }

            if ($redirect->to_path === $path) {
                break;
            }

            $path = $redirect->to_path;
        }

        return $path;
    }
}
