<?php

namespace App\Http\Controllers\Discovery;

use App\Http\Controllers\Controller;
use App\Models\SeoLandingPage;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $pages = SeoLandingPage::query()
            ->inSitemap()
            ->orderBy('slug')
            ->get(['id', 'slug', 'updated_at']);

        return response()
            ->view('sitemaps.index', [
                'pages' => $pages,
            ])
            ->header('Content-Type', 'application/xml');
    }
}
