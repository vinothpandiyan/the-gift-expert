<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\NavigationLink;
use App\Models\NavigationMenu;
use App\Models\NavigationSection;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Models\SeoLandingPage;
use App\Observers\FlushPrimaryNavigationCache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $flush = FlushPrimaryNavigationCache::class;

        NavigationMenu::observe($flush);
        NavigationSection::observe($flush);
        NavigationLink::observe($flush);
        SeoLandingPage::observe($flush);
        Category::observe($flush);
        Relationship::observe($flush);
        Occasion::observe($flush);
        Interest::observe($flush);
        Profession::observe($flush);
        RecipientType::observe($flush);
        GiftType::observe($flush);
    }
}
