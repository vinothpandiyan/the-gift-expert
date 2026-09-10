<?php

namespace App\Http\Controllers\Discovery;

use App\Actions\Discovery\QueryGiftDetailAction;
use App\Actions\Discovery\QueryRelatedGiftsAction;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSlugRedirect;
use App\Support\DiscoveryUrl;
use App\Support\PageMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class GiftController extends Controller
{
    public function show(
        string $slug,
        QueryGiftDetailAction $queryGiftDetail,
        QueryRelatedGiftsAction $queryRelatedGifts,
    ): RedirectResponse|View {
        $detail = $queryGiftDetail->execute($slug);

        if ($detail !== null) {
            $context = request()->query('context');
            $breadcrumbs = PageMeta::giftBreadcrumbs(
                $detail->product,
                is_string($context) ? $context : null,
            );
            $canonical = PageMeta::giftCanonical($detail->product);
            $description = PageMeta::giftDescription($detail->product);
            $primaryImage = $detail->galleryImages->first();

            return view('discovery.gifts.show', [
                'detail' => $detail,
                'product' => $detail->product,
                'relatedProducts' => $queryRelatedGifts->execute($detail->product),
                'seoTitle' => PageMeta::giftTitle($detail->product),
                'seoDescription' => $description,
                'seoCanonical' => $canonical,
                'seoRobots' => 'index, follow',
                'seoOpenGraphTitle' => PageMeta::giftTitle($detail->product),
                'seoOpenGraphDescription' => $description,
                'seoOpenGraphImage' => $primaryImage?->url(),
                'productStructuredData' => PageMeta::giftProductStructuredData($detail->product),
                'breadcrumbStructuredData' => PageMeta::breadcrumbStructuredData($breadcrumbs, $canonical),
                'breadcrumbs' => $breadcrumbs,
            ]);
        }

        $redirect = ProductSlugRedirect::query()
            ->where('from_slug', $slug)
            ->first();

        if ($redirect === null) {
            abort(404);
        }

        $target = Product::query()
            ->published()
            ->where('slug', $redirect->to_slug)
            ->first();

        if ($target === null) {
            abort(404);
        }

        $context = request()->query('context');

        return redirect(DiscoveryUrl::gift(
            $target->slug,
            context: is_string($context) && $context !== '' ? $context : null,
        ), 301);
    }
}
