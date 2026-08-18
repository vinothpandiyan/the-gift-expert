<?php

namespace Tests\Feature\CatalogCandidate;

use App\Actions\CatalogCandidate\ApproveCatalogCandidateAction;
use App\Actions\CatalogCandidate\CreateCatalogCandidateAction;
use App\Actions\CatalogCandidate\CreateCatalogCandidateEvidenceAction;
use App\Actions\CatalogCandidate\FindCatalogCandidateProductOverlapAction;
use App\Actions\CatalogCandidate\RejectCatalogCandidateAction;
use App\Actions\CatalogCandidate\StartReviewCatalogCandidateAction;
use App\Enums\CatalogCandidateSourceType;
use App\Enums\ProductStatus;
use App\Models\AffiliateLink;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CatalogCandidateIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_review_and_reject_do_not_touch_the_product_catalog(): void
    {
        $before = $this->catalogCounts();
        $reviewer = User::factory()->create();

        $candidate = app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'Portable Photo Printer',
            'source_type' => CatalogCandidateSourceType::Web,
            'source_url' => 'https://example.com/idea',
        ]);

        app(CreateCatalogCandidateEvidenceAction::class)->execute($candidate, [
            'source_type' => CatalogCandidateSourceType::Community,
            'source_url' => 'https://example.com/thread',
            'summary' => 'Recommended in discussion.',
        ]);

        app(StartReviewCatalogCandidateAction::class)->execute($candidate, $reviewer);
        app(ApproveCatalogCandidateAction::class)->execute($candidate->fresh(), $reviewer);
        app(RejectCatalogCandidateAction::class)->execute($candidate->fresh(), $reviewer);

        $this->assertSame($before, $this->catalogCounts());
        $this->assertSame(14, collect(Route::getRoutes())->filter(
            fn ($route): bool => str_starts_with((string) $route->getName(), 'discovery.'),
        )->count());
    }

    public function test_product_name_overlap_is_a_warning_and_does_not_block_create(): void
    {
        Product::factory()->create([
            'name' => 'Portable Photo Printer',
            'status' => ProductStatus::Draft,
        ]);

        $before = $this->catalogCounts();

        $candidate = app(CreateCatalogCandidateAction::class)->execute([
            'title' => 'portable photo printer',
            'source_type' => CatalogCandidateSourceType::Manual,
        ]);

        $matches = app(FindCatalogCandidateProductOverlapAction::class)->execute($candidate);

        $this->assertCount(1, $matches);
        $this->assertSame('Portable Photo Printer', $matches->first()->name);
        $this->assertSame($before, $this->catalogCounts());
    }

    /**
     * @return array<string, int>
     */
    private function catalogCounts(): array
    {
        return [
            'products' => Product::query()->withTrashed()->count(),
            'affiliate_links' => AffiliateLink::query()->withTrashed()->count(),
            'product_images' => ProductImage::query()->count(),
            'import_runs' => ImportRun::query()->count(),
            'category_product' => DB::table('category_product')->count(),
            'occasion_product' => DB::table('occasion_product')->count(),
            'relationship_product' => DB::table('relationship_product')->count(),
            'recipient_type_product' => DB::table('recipient_type_product')->count(),
            'interest_product' => DB::table('interest_product')->count(),
            'profession_product' => DB::table('profession_product')->count(),
            'gift_type_product' => DB::table('gift_type_product')->count(),
        ];
    }
}
