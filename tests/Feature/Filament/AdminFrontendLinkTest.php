<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Livewire\Topbar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminFrontendLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_topbar_shows_a_frontend_link_before_search(): void
    {
        $this->actingAs(User::factory()->create());

        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();

        $html = Livewire::test(Topbar::class)->html();

        $this->assertStringContainsString('data-frontend-link', $html);
        $this->assertStringContainsString('View site', $html);
        $this->assertStringContainsString('href="'.e(route('home')).'"', $html);
        $this->assertStringContainsString('target="_blank"', $html);

        $frontendPosition = strpos($html, 'data-frontend-link');
        $searchPosition = strpos($html, 'fi-global-search');

        $this->assertNotFalse($frontendPosition);
        $this->assertNotFalse($searchPosition);
        $this->assertLessThan($searchPosition, $frontendPosition);
    }
}
