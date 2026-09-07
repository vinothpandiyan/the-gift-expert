<?php

namespace Tests\Unit\Support;

use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use App\Support\DiscoveryUrl;
use App\Support\GiftIdeasHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GiftIdeasHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_curated_destinations_in_config_order_and_skips_missing_rows(): void
    {
        Relationship::query()->create(['name' => 'Husband', 'slug' => 'husband', 'is_active' => true]);
        Relationship::query()->create(['name' => 'Newlyweds', 'slug' => 'newlyweds', 'is_active' => true]);
        RecipientType::query()->create(['name' => 'Kids', 'slug' => 'kids', 'is_active' => true]);
        Occasion::query()->create(['name' => 'Birthday', 'slug' => 'birthday', 'is_active' => true]);
        Occasion::query()->create(['name' => 'Festival', 'slug' => 'festival', 'is_active' => false]);
        Interest::query()->create(['name' => 'Tech & Gadgets', 'slug' => 'technology', 'is_active' => true]);
        GiftType::query()->create(['name' => 'Personalized Gifts', 'slug' => 'personalized-gifts', 'is_active' => true]);
        GiftType::query()->create(['name' => 'Online Courses', 'slug' => 'online-courses', 'is_active' => false]);
        Profession::query()->create(['name' => 'Doctor', 'slug' => 'doctor', 'is_active' => true]);
        $home = Category::query()->create(['name' => 'Home & Living', 'slug' => 'home-and-living', 'is_active' => true]);
        Category::query()->create([
            'name' => 'Kitchen & Dining',
            'slug' => 'kitchen-and-dining',
            'parent_id' => $home->id,
            'is_active' => true,
        ]);

        $hub = GiftIdeasHub::resolve();

        $this->assertSame(['husband', 'kids'], array_column($hub['recipients'], 'slug'));
        $this->assertSame(DiscoveryUrl::relationship('husband'), $hub['recipients'][0]['href']);
        $this->assertSame(DiscoveryUrl::recipientType('kids'), $hub['recipients'][1]['href']);
        $this->assertSame(['birthday'], array_column($hub['occasions'], 'slug'));
        $this->assertSame(['technology'], array_column($hub['interests'], 'slug'));
        $this->assertSame(['personalized-gifts'], array_column($hub['giftTypes'], 'slug'));
        $this->assertSame(DiscoveryUrl::giftType('personalized-gifts'), $hub['giftTypes'][0]['href']);
        $this->assertSame(['home-and-living'], array_column($hub['categories'], 'slug'));
        $this->assertSame([], array_filter(
            $hub['categories'],
            fn (array $item): bool => $item['slug'] === 'kitchen-and-dining',
        ));
        $this->assertFalse(collect($hub['occasions'])->contains(fn (array $item): bool => $item['slug'] === 'festival'));
        $this->assertFalse(collect($hub['giftTypes'])->contains(fn (array $item): bool => $item['slug'] === 'online-courses'));
        $this->assertFalse(collect($hub['recipients'])->contains(fn (array $item): bool => $item['slug'] === 'newlyweds'));
    }
}
