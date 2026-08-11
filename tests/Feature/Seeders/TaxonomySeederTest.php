<?php

namespace Tests\Feature\Seeders;

use App\Enums\ProductStatus;
use App\Models\BudgetRange;
use App\Models\Category;
use App\Models\GiftType;
use App\Models\Interest;
use App\Models\Occasion;
use App\Models\Product;
use App\Models\Profession;
use App\Models\RecipientType;
use App\Models\Relationship;
use Database\Seeders\BudgetRangeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\GiftTypeSeeder;
use Database\Seeders\InterestSeeder;
use Database\Seeders\MerchantSeeder;
use Database\Seeders\OccasionSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\ProfessionSeeder;
use Database\Seeders\RecipientTypeSeeder;
use Database\Seeders\RelationshipSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxonomySeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, class-string<Seeder>>
     */
    private array $taxonomySeeders = [
        BudgetRangeSeeder::class,
        OccasionSeeder::class,
        RelationshipSeeder::class,
        RecipientTypeSeeder::class,
        InterestSeeder::class,
        ProfessionSeeder::class,
        GiftTypeSeeder::class,
        CategorySeeder::class,
    ];

    public function test_taxonomy_seeders_run_without_error(): void
    {
        $this->seed($this->taxonomySeeders);

        $this->assertSame(6, BudgetRange::query()->count());
        $this->assertSame(15, Occasion::query()->count());
        $this->assertSame(16, Relationship::query()->count());
        $this->assertSame(6, RecipientType::query()->count());
        $this->assertSame(10, Interest::query()->count());
        $this->assertSame(8, Profession::query()->count());
        $this->assertSame(6, GiftType::query()->count());
        $this->assertSame(13, Category::query()->count());
        $this->assertSame(
            'gifts-for-him/gifts-for-husband',
            Category::query()->where('slug', 'gifts-for-husband')->value('full_path'),
        );
    }

    public function test_taxonomy_seeders_are_idempotent(): void
    {
        $this->seed($this->taxonomySeeders);

        $counts = [
            BudgetRange::query()->count(),
            Occasion::query()->count(),
            Relationship::query()->count(),
            RecipientType::query()->count(),
            Interest::query()->count(),
            Profession::query()->count(),
            GiftType::query()->count(),
            Category::query()->count(),
        ];

        $this->seed($this->taxonomySeeders);

        $this->assertSame($counts, [
            BudgetRange::query()->count(),
            Occasion::query()->count(),
            Relationship::query()->count(),
            RecipientType::query()->count(),
            Interest::query()->count(),
            Profession::query()->count(),
            GiftType::query()->count(),
            Category::query()->count(),
        ]);
    }

    public function test_database_seeder_creates_development_products_without_publication_violations(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Product::query()->where('slug', 'draft-gift-idea')->where('status', ProductStatus::Draft)->count());
        $this->assertSame(1, Product::query()->where('slug', 'personalized-wooden-photo-frame')->where('status', ProductStatus::Published)->count());
        $this->assertSame(1, Product::query()->published()->count());
    }

    public function test_product_seeder_is_idempotent(): void
    {
        $this->seed([
            MerchantSeeder::class,
            ...$this->taxonomySeeders,
            ProductSeeder::class,
        ]);

        $productCount = Product::query()->count();

        $this->seed([
            MerchantSeeder::class,
            ...$this->taxonomySeeders,
            ProductSeeder::class,
        ]);

        $this->assertSame($productCount, Product::query()->count());
        $this->assertSame(1, Product::query()->published()->count());
    }
}
