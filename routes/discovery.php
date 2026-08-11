<?php

use App\Http\Controllers\Discovery\CategoryController;
use App\Http\Controllers\Discovery\GiftController;
use App\Http\Controllers\Discovery\TaxonomyController;
use Illuminate\Support\Facades\Route;

/**
 * Public discovery routes. Paths come from config/discovery.php.
 * Finder and affiliate /out routes are intentionally not registered in Phase 6.
 */
$uri = static fn (string $name): string => ltrim((string) (config('discovery.routes')[$name] ?? ''), '/');

Route::get($uri('gift.show'), [GiftController::class, 'show'])
    ->name('discovery.gift.show');

Route::get($uri('occasion.show'), [TaxonomyController::class, 'show'])
    ->defaults('taxonomy', 'occasion')
    ->name('discovery.occasion.show');

Route::get($uri('relationship.show'), [TaxonomyController::class, 'show'])
    ->defaults('taxonomy', 'relationship')
    ->name('discovery.relationship.show');

Route::get($uri('recipient_type.show'), [TaxonomyController::class, 'show'])
    ->defaults('taxonomy', 'recipient_type')
    ->name('discovery.recipient_type.show');

Route::get($uri('interest.show'), [TaxonomyController::class, 'show'])
    ->defaults('taxonomy', 'interest')
    ->name('discovery.interest.show');

Route::get($uri('profession.show'), [TaxonomyController::class, 'show'])
    ->defaults('taxonomy', 'profession')
    ->name('discovery.profession.show');

Route::get($uri('gift_type.show'), [TaxonomyController::class, 'show'])
    ->defaults('taxonomy', 'gift_type')
    ->name('discovery.gift_type.show');

Route::get($uri('gift_ideas.category'), [CategoryController::class, 'show'])
    ->where('full_path', '.+')
    ->name('discovery.gift_ideas.category');
