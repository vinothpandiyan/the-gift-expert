<?php

namespace Tests\Unit\Actions;

use App\Actions\Category\RecordCategoryPathRedirectsAction;
use App\Models\Category;
use App\Models\CategoryPathRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordCategoryPathRedirectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_slug_change_records_path_redirect(): void
    {
        $category = Category::query()->create([
            'name' => 'Gifts for Him',
            'slug' => 'gifts-for-him',
            'full_path' => 'gifts-for-him',
        ]);

        $category->update(['slug' => 'gifts-for-men']);

        $this->assertDatabaseHas('category_path_redirects', [
            'from_path' => 'gifts-for-him',
            'to_path' => 'gifts-for-men',
        ]);
    }

    public function test_manual_redirect_recording_collapses_chains(): void
    {
        CategoryPathRedirect::query()->create([
            'from_path' => 'root-a',
            'to_path' => 'root-b',
        ]);

        app(RecordCategoryPathRedirectsAction::class)
            ->record('root-b', 'root-c');

        $this->assertDatabaseHas('category_path_redirects', [
            'from_path' => 'root-a',
            'to_path' => 'root-c',
        ]);

        $this->assertDatabaseHas('category_path_redirects', [
            'from_path' => 'root-b',
            'to_path' => 'root-c',
        ]);
    }
}
