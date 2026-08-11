<?php

namespace App\Http\Controllers\Discovery;

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

    public function show(string $full_path): RedirectResponse|View
    {
        $path = $full_path;

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

        if ($path !== $full_path) {
            $target = Category::query()
                ->where('full_path', $path)
                ->where('is_active', true)
                ->first();

            if ($target === null) {
                abort(404);
            }

            return redirect(DiscoveryUrl::giftIdeasCategory($target->full_path), 301);
        }

        $category = Category::query()
            ->where('full_path', $full_path)
            ->where('is_active', true)
            ->with('parent')
            ->first();

        if ($category === null) {
            abort(404);
        }

        $children = $category->children()
            ->where('is_active', true)
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
            'products' => $products,
            'seoTitle' => PageMeta::categoryTitle($category),
            'seoDescription' => PageMeta::categoryDescription($category),
            'seoCanonical' => $pagination['canonical'],
            'seoRobots' => 'index, follow',
            'seoPrev' => $pagination['prev'],
            'seoNext' => $pagination['next'],
            'breadcrumbs' => PageMeta::categoryBreadcrumbs($category),
        ]);
    }
}
