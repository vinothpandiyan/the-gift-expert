<?php

namespace App\Http\Controllers;

use App\Actions\Home\QueryFeaturedHomepageGiftsAction;
use App\Actions\Home\QueryHomepageInspirationPagesAction;
use App\Actions\Home\QueryHomepageTaxonomiesAction;
use App\Support\PageMeta;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(
        QueryHomepageTaxonomiesAction $queryTaxonomies,
        QueryFeaturedHomepageGiftsAction $queryFeaturedGifts,
        QueryHomepageInspirationPagesAction $queryInspirationPages,
    ): View {
        $taxonomies = $queryTaxonomies->execute();

        return view('home', [
            'relationships' => $taxonomies['relationships'],
            'occasions' => $taxonomies['occasions'],
            'interests' => $taxonomies['interests'],
            'budgetRanges' => $taxonomies['budgetRanges'],
            'returnGifts' => $taxonomies['returnGifts'],
            'featuredGifts' => $queryFeaturedGifts->execute(),
            'inspirationPages' => $queryInspirationPages->execute(),
            'seoTitle' => PageMeta::homeTitle(),
            'seoDescription' => PageMeta::homeDescription(),
            'seoCanonical' => PageMeta::homeCanonical(),
            'seoRobots' => 'index, follow',
        ]);
    }
}
