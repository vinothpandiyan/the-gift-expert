<?php

namespace App\Actions\Home;

use App\Models\SeoLandingPage;
use Illuminate\Support\Collection;

class QueryHomepageInspirationPagesAction
{
    public const LIMIT = 4;

    /**
     * @return Collection<int, SeoLandingPage>
     */
    public function execute(int $limit = self::LIMIT): Collection
    {
        $limit = max(1, $limit);

        return SeoLandingPage::query()
            ->discoverable()
            ->orderBy('sort_order')
            ->orderBy('heading')
            ->limit($limit)
            ->get(['id', 'name', 'slug', 'heading', 'intro_content']);
    }
}
