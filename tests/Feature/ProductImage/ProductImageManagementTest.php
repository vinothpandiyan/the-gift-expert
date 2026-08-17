<?php

namespace Tests\Feature\ProductImage;

use App\Actions\ProductImage\DeleteProductImageAction;
use App\Actions\ProductImage\SetPrimaryProductImageAction;
use App\Actions\ProductImage\StoreProductImageAction;
use App\Filament\Resources\Gifts\Pages\EditGift;
use App\Filament\Resources\Gifts\RelationManagers\ImagesRelationManager;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\DiscoveryUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\MakesRasterImages;
use Tests\TestCase;

class ProductImageManagementTest extends TestCase
{
    use MakesRasterImages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_it_stores_multiple_processed_images_with_independent_paths(): void
    {
        $product = Product::factory()->create();

        $images = app(StoreProductImageAction::class)->execute($product, [
            $this->rasterImagePath(1200, 800),
            $this->rasterImagePath(900, 900),
        ]);

        $this->assertCount(2, $images);
        $this->assertCount(2, $product->images);
        $this->assertTrue($images[0]->is_primary);
        $this->assertFalse($images[1]->is_primary);
        $this->assertNotSame($images[0]->path, $images[1]->path);

        foreach ($images as $image) {
            $this->assertStringStartsWith('products/'.$product->id.'/images/', $image->path);
            $this->assertStringEndsWith('.webp', $image->path);
            $this->assertDoesNotMatchRegularExpression('/filament|import|csv|upload/i', $image->path);
            Storage::disk('public')->assertExists($image->path);
        }
    }

    public function test_a_failed_image_in_a_batch_does_not_leave_records_or_files(): void
    {
        $product = Product::factory()->create();
        $invalid = tempnam(sys_get_temp_dir(), 'gift-invalid-');
        file_put_contents($invalid, 'not-an-image');

        try {
            app(StoreProductImageAction::class)->execute($product, [
                $this->rasterImagePath(400, 400),
                $invalid,
            ]);
            $this->fail('Expected the invalid image to fail the batch.');
        } catch (ValidationException) {
            $this->assertSame(0, $product->images()->count());
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }

    public function test_changing_primary_unsets_the_previous_primary(): void
    {
        $product = Product::factory()->create();
        $images = app(StoreProductImageAction::class)->execute($product, [
            $this->rasterImagePath(400, 400),
            $this->rasterImagePath(400, 400),
        ]);

        app(SetPrimaryProductImageAction::class)->execute($images[1]);

        $this->assertFalse($images[0]->fresh()->is_primary);
        $this->assertTrue($images[1]->fresh()->is_primary);
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
    }

    public function test_deleting_an_image_removes_the_file_and_promotes_a_primary(): void
    {
        $product = Product::factory()->create();
        $images = app(StoreProductImageAction::class)->execute($product, [
            $this->rasterImagePath(400, 400),
            $this->rasterImagePath(400, 400),
        ]);

        $primaryPath = $images[0]->path;

        app(DeleteProductImageAction::class)->execute($images[0]);

        $this->assertDatabaseMissing('product_images', ['id' => $images[0]->id]);
        Storage::disk('public')->assertMissing($primaryPath);
        Storage::disk('public')->assertExists($images[1]->path);
        $this->assertTrue($images[1]->fresh()->is_primary);
    }

    public function test_deleting_a_record_with_a_missing_file_does_not_fail(): void
    {
        $product = Product::factory()->create();
        $image = ProductImage::query()->create([
            'product_id' => $product->id,
            'disk' => 'public',
            'path' => 'products/'.$product->id.'/images/missing.webp',
            'sort_order' => 1,
            'is_primary' => true,
        ]);

        app(DeleteProductImageAction::class)->execute($image);

        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
    }

    public function test_filament_can_upload_reorder_and_change_primary_images(): void
    {
        $this->actingAs(User::factory()->create());

        $product = Product::factory()->create();
        $first = UploadedFile::fake()->image('first.jpg', 400, 400);
        $second = UploadedFile::fake()->image('second.jpg', 400, 400);

        Livewire::test(ImagesRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditGift::class,
        ])
            ->callTableAction('create', data: [
                'uploads' => [$first, $second],
                'alt_text' => 'Gift photo',
                'is_primary' => true,
            ])
            ->assertHasNoTableActionErrors();

        $product->refresh();
        $this->assertCount(2, $product->images);
        $this->assertSame('Gift photo', $product->images->first()->alt_text);
        $this->assertTrue($product->images->first()->is_primary);

        $ordered = $product->images()->orderBy('id')->get();
        Livewire::test(ImagesRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditGift::class,
        ])
            ->call('reorderTable', [$ordered[1]->id, $ordered[0]->id]);

        $this->assertSame(
            [$ordered[1]->id, $ordered[0]->id],
            $product->images()->orderBy('sort_order')->pluck('id')->all(),
        );

        Livewire::test(ImagesRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditGift::class,
        ])
            ->callTableAction('edit', $ordered[1], data: [
                'alt_text' => 'Updated',
                'is_primary' => true,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($ordered[1]->fresh()->is_primary);
        $this->assertFalse($ordered[0]->fresh()->is_primary);
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
    }

    public function test_public_gift_detail_still_renders_product_image_urls(): void
    {
        $product = Product::factory()->published()->create(['slug' => 'framed-print']);
        $images = app(StoreProductImageAction::class)->execute($product, [
            $this->rasterImagePath(400, 400),
        ]);

        $this->get(DiscoveryUrl::gift($product->slug))
            ->assertOk()
            ->assertSee('src="'.$images->first()->url().'"', false);
    }
}
