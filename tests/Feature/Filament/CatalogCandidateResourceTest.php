<?php

namespace Tests\Feature\Filament;

use App\Enums\CatalogCandidatePriority;
use App\Enums\CatalogCandidateSourceType;
use App\Enums\CatalogCandidateStatus;
use App\Enums\ProductStatus;
use App\Filament\Resources\CatalogCandidates\CatalogCandidateResource;
use App\Filament\Resources\CatalogCandidates\Pages\CreateCatalogCandidate;
use App\Filament\Resources\CatalogCandidates\Pages\EditCatalogCandidate;
use App\Filament\Resources\CatalogCandidates\Pages\ListCatalogCandidates;
use App\Filament\Resources\CatalogCandidates\RelationManagers\EvidenceRelationManager;
use App\Models\AffiliateLink;
use App\Models\CatalogCandidate;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\CatalogCandidateTitleFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogCandidateResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_is_registered_and_pages_load(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertSame(CatalogCandidate::class, CatalogCandidateResource::getModel());

        Livewire::test(ListCatalogCandidates::class)->assertOk();
        Livewire::test(CreateCatalogCandidate::class)->assertOk();

        $candidate = CatalogCandidate::factory()->create();

        Livewire::test(EditCatalogCandidate::class, [
            'record' => $candidate->getRouteKey(),
        ])->assertOk();
    }

    public function test_it_creates_a_discovered_candidate_with_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $before = $this->catalogCounts();

        Livewire::test(CreateCatalogCandidate::class)
            ->fillForm([
                'title' => 'Portable Photo Printer',
                'source_type' => CatalogCandidateSourceType::Manual->value,
                'priority' => CatalogCandidatePriority::Normal->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $candidate = CatalogCandidate::query()->where('title', 'Portable Photo Printer')->first();

        $this->assertNotNull($candidate);
        $this->assertSame(CatalogCandidateStatus::Discovered, $candidate->status);
        $this->assertSame($user->id, $candidate->created_by_user_id);
        $this->assertSame($before, $this->catalogCounts());
    }

    public function test_duplicate_external_reference_errors_are_surfaced(): void
    {
        $this->actingAs(User::factory()->create());

        CatalogCandidate::factory()->create([
            'title' => 'First Wallet',
            'source_type' => CatalogCandidateSourceType::Merchant,
            'source_name' => 'Amazon',
            'external_reference' => 'B0EXAMPLE',
        ]);

        Livewire::test(CreateCatalogCandidate::class)
            ->fillForm([
                'title' => 'Second Wallet',
                'source_type' => CatalogCandidateSourceType::Merchant->value,
                'source_name' => 'amazon',
                'external_reference' => 'B0EXAMPLE',
            ])
            ->call('create')
            ->assertHasFormErrors(['external_reference']);
    }

    public function test_similar_title_is_blocked_unless_explicitly_allowed(): void
    {
        $this->actingAs(User::factory()->create());

        CatalogCandidate::factory()->create([
            'title' => 'Portable Photo Printer',
        ]);

        Livewire::test(CreateCatalogCandidate::class)
            ->fillForm([
                'title' => 'Portable Photo-Printer',
                'source_type' => CatalogCandidateSourceType::Editorial->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['title']);

        Livewire::test(CreateCatalogCandidate::class)
            ->fillForm([
                'title' => 'Portable Photo-Printer',
                'source_type' => CatalogCandidateSourceType::Editorial->value,
                'allow_similar_title' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, CatalogCandidate::query()->count());
    }

    public function test_edit_recomputes_fingerprint_and_cannot_free_edit_status(): void
    {
        $this->actingAs(User::factory()->create());

        $candidate = CatalogCandidate::factory()->create([
            'title' => 'Old Title',
        ]);

        Livewire::test(EditCatalogCandidate::class, [
            'record' => $candidate->getRouteKey(),
        ])
            ->fillForm([
                'title' => 'Portable Photo Printer',
                'status' => CatalogCandidateStatus::Approved->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $candidate->refresh();

        $this->assertSame('Portable Photo Printer', $candidate->title);
        $this->assertSame(
            CatalogCandidateTitleFingerprint::from('Portable Photo Printer'),
            $candidate->title_fingerprint,
        );
        $this->assertSame(CatalogCandidateStatus::Discovered, $candidate->status);
    }

    public function test_edit_respects_identity_dedupe(): void
    {
        $this->actingAs(User::factory()->create());

        CatalogCandidate::factory()->create([
            'title' => 'Leather Wallet',
            'source_type' => CatalogCandidateSourceType::Merchant,
            'source_name' => 'Amazon',
            'external_reference' => 'B0EXAMPLE',
        ]);

        $candidate = CatalogCandidate::factory()->create([
            'title' => 'Travel Mug',
            'source_type' => CatalogCandidateSourceType::Manual,
        ]);

        Livewire::test(EditCatalogCandidate::class, [
            'record' => $candidate->getRouteKey(),
        ])
            ->fillForm([
                'title' => 'Travel Mug',
                'source_type' => CatalogCandidateSourceType::Merchant->value,
                'source_name' => 'amazon',
                'external_reference' => 'B0EXAMPLE',
            ])
            ->call('save')
            ->assertHasFormErrors(['external_reference']);
    }

    public function test_lifecycle_action_visibility_and_reviewer_attribution(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $candidate = CatalogCandidate::factory()->create();

        Livewire::test(EditCatalogCandidate::class, [
            'record' => $candidate->getRouteKey(),
        ])
            ->assertActionVisible('startReview')
            ->assertActionHidden('approve')
            ->assertActionVisible('reject')
            ->assertActionHidden('reopen')
            ->assertActionDoesNotExist('promote')
            ->assertActionDoesNotExist('publish')
            ->callAction('startReview')
            ->assertHasNoActionErrors();

        $candidate->refresh();
        $this->assertSame(CatalogCandidateStatus::UnderReview, $candidate->status);
        $this->assertSame($user->id, $candidate->reviewed_by_user_id);
        $this->assertNull($candidate->reviewed_at);

        Livewire::test(EditCatalogCandidate::class, [
            'record' => $candidate->getRouteKey(),
        ])
            ->assertActionHidden('startReview')
            ->assertActionVisible('approve')
            ->assertActionVisible('reject')
            ->callAction('approve')
            ->assertHasNoActionErrors();

        $candidate->refresh();
        $this->assertSame(CatalogCandidateStatus::Approved, $candidate->status);
        $this->assertNotNull($candidate->reviewed_at);

        Livewire::test(EditCatalogCandidate::class, [
            'record' => $candidate->getRouteKey(),
        ])
            ->assertActionHidden('approve')
            ->assertActionVisible('reject')
            ->callAction('reject')
            ->assertHasNoActionErrors();

        $candidate->refresh();
        $this->assertSame(CatalogCandidateStatus::Rejected, $candidate->status);

        Livewire::test(EditCatalogCandidate::class, [
            'record' => $candidate->getRouteKey(),
        ])
            ->assertActionVisible('reopen')
            ->assertActionHidden('reject')
            ->callAction('reopen')
            ->assertHasNoActionErrors();

        $this->assertSame(CatalogCandidateStatus::UnderReview, $candidate->fresh()->status);
        $this->assertNull($candidate->fresh()->reviewed_at);
    }

    public function test_hidden_approve_does_not_change_a_discovered_candidate(): void
    {
        $this->actingAs(User::factory()->create());

        $candidate = CatalogCandidate::factory()->create();

        Livewire::test(EditCatalogCandidate::class, [
            'record' => $candidate->getRouteKey(),
        ])
            ->assertActionHidden('approve');

        $this->assertSame(CatalogCandidateStatus::Discovered, $candidate->fresh()->status);
    }

    public function test_evidence_relation_manager_creates_through_the_action(): void
    {
        $this->actingAs(User::factory()->create());

        $candidate = CatalogCandidate::factory()->create([
            'source_url' => 'https://example.com/idea',
        ]);
        $other = CatalogCandidate::factory()->create();

        Livewire::test(EvidenceRelationManager::class, [
            'ownerRecord' => $candidate,
            'pageClass' => EditCatalogCandidate::class,
        ])
            ->callTableAction('create', data: [
                'source_type' => CatalogCandidateSourceType::Web->value,
                'source_url' => 'https://example.com/thread',
                'summary' => 'Mentioned in discussion.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('catalog_candidate_evidence', [
            'catalog_candidate_id' => $candidate->id,
            'source_url' => 'https://example.com/thread',
        ]);
        $this->assertDatabaseMissing('catalog_candidate_evidence', [
            'catalog_candidate_id' => $candidate->id,
            'source_url' => 'https://example.com/idea',
        ]);

        Livewire::test(EvidenceRelationManager::class, [
            'ownerRecord' => $candidate,
            'pageClass' => EditCatalogCandidate::class,
        ])
            ->callTableAction('create', data: [
                'source_type' => CatalogCandidateSourceType::Community->value,
                'source_url' => 'https://example.com/thread',
            ]);

        $this->assertSame(1, $candidate->evidence()->where('source_url', 'https://example.com/thread')->count());

        Livewire::test(EvidenceRelationManager::class, [
            'ownerRecord' => $other,
            'pageClass' => EditCatalogCandidate::class,
        ])
            ->callTableAction('create', data: [
                'source_type' => CatalogCandidateSourceType::Web->value,
                'source_url' => 'https://example.com/thread',
            ])
            ->assertHasNoTableActionErrors();

        Livewire::test(EvidenceRelationManager::class, [
            'ownerRecord' => $candidate,
            'pageClass' => EditCatalogCandidate::class,
        ])
            ->callTableAction('create', data: [
                'source_type' => CatalogCandidateSourceType::Web->value,
                'summary' => '<html><body>Copied article</body></html>',
            ]);

        $this->assertFalse(
            $candidate->evidence()->where('summary', 'like', '%Copied article%')->exists(),
        );
    }

    public function test_list_filters_status_priority_source_type_and_trashed(): void
    {
        $this->actingAs(User::factory()->create());

        $discovered = CatalogCandidate::factory()->create([
            'title' => 'Discovered Idea',
            'status' => CatalogCandidateStatus::Discovered,
            'priority' => CatalogCandidatePriority::High,
            'source_type' => CatalogCandidateSourceType::Web,
        ]);
        $approved = CatalogCandidate::factory()->create([
            'title' => 'Approved Idea',
            'status' => CatalogCandidateStatus::Approved,
            'priority' => CatalogCandidatePriority::Low,
            'source_type' => CatalogCandidateSourceType::Manual,
        ]);
        $trashed = CatalogCandidate::factory()->create([
            'title' => 'Trashed Idea',
        ]);
        $trashed->delete();

        Livewire::test(ListCatalogCandidates::class)
            ->filterTable('status', CatalogCandidateStatus::Approved->value)
            ->assertCanSeeTableRecords([$approved])
            ->assertCanNotSeeTableRecords([$discovered]);

        Livewire::test(ListCatalogCandidates::class)
            ->filterTable('priority', CatalogCandidatePriority::High->value)
            ->assertCanSeeTableRecords([$discovered])
            ->assertCanNotSeeTableRecords([$approved]);

        Livewire::test(ListCatalogCandidates::class)
            ->filterTable('source_type', CatalogCandidateSourceType::Web->value)
            ->assertCanSeeTableRecords([$discovered])
            ->assertCanNotSeeTableRecords([$approved]);

        Livewire::test(ListCatalogCandidates::class)
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$trashed])
            ->assertCanNotSeeTableRecords([$discovered, $approved]);
    }

    public function test_product_name_overlap_warning_does_not_block_save(): void
    {
        $this->actingAs(User::factory()->create());

        Product::factory()->create([
            'name' => 'Portable Photo Printer',
            'status' => ProductStatus::Draft,
        ]);
        $before = $this->catalogCounts();

        Livewire::test(CreateCatalogCandidate::class)
            ->fillForm([
                'title' => 'Portable Photo Printer',
                'source_type' => CatalogCandidateSourceType::Manual->value,
            ])
            ->assertSee('This title matches an existing gift')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($before, $this->catalogCounts());
        $this->assertSame(1, CatalogCandidate::query()->count());
    }

    public function test_architecture_invariants_hold(): void
    {
        $this->actingAs(User::factory()->create());

        $beforePivots = $this->catalogCounts();
        CatalogCandidate::factory()->create();

        $this->assertSame($beforePivots, $this->catalogCounts());
        $this->assertSame(14, collect(Route::getRoutes())->filter(
            fn ($route): bool => str_starts_with((string) $route->getName(), 'discovery.'),
        )->count());
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
