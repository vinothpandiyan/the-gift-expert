<?php

namespace Tests\Feature\GapSourcing;

use App\Enums\ProductStatus;
use App\Models\CatalogCandidate;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Relationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GapSourcingShortlistCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_prints_a_shortlist_without_creating_products_or_wishlists(): void
    {
        Relationship::query()->create(['name' => 'Father', 'slug' => 'father', 'is_active' => true]);
        GiftType::query()->create(['name' => 'Experience Gifts', 'slug' => 'experience-gifts', 'is_active' => true]);
        Occasion::query()->create(['name' => "Mother's Day", 'slug' => 'mothers-day', 'is_active' => true]);
        Interest::query()->create(['name' => 'Pet Parent', 'slug' => 'pets', 'is_active' => true]);
        Interest::query()->create(['name' => 'Eco-Conscious', 'slug' => 'eco-friendly', 'is_active' => true]);

        $path = storage_path('app/gap-sourcing-command-test.json');
        File::put($path, json_encode([
            'candidates' => [
                [
                    'id' => 'dad-leatherman',
                    'title' => 'Leatherman Wave Plus',
                    'merchant' => 'amazon-in',
                    'source_url' => 'https://www.amazon.in/dp/B079MJ6MLV',
                    'asin' => 'B079MJ6MLV',
                    'target_gap' => 'father',
                    'concept' => 'premium-edc-multitool',
                    'price_amount' => 15990,
                    'gift_potential' => 'high',
                    'father_specific_reason' => 'A keep-forever practical gift for a father who fixes things.',
                    'differentiated' => true,
                    'availability' => 'in_stock',
                    'evidence_confidence' => 'high',
                ],
            ],
        ]));

        $before = Product::query()->withTrashed()->count();

        $this->artisan('catalog:gap-sourcing-shortlist', ['file' => $path, '--json' => true])
            ->expectsOutputToContain('"triage": "shortlist"')
            ->expectsOutputToContain('No Product was created, published, archived, or added to a wishlist.')
            ->assertSuccessful();

        $this->assertSame($before, Product::query()->withTrashed()->count());
        $this->assertSame(0, Product::query()->where('status', ProductStatus::Published)->count());
        $this->assertSame(0, Product::query()->where('status', ProductStatus::Archived)->count());
        $this->assertSame(0, CatalogCandidate::query()->count());

        File::delete($path);
    }
}
