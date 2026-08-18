<?php

namespace Tests\Feature\CatalogCandidate;

use App\Enums\CatalogCandidateIngestionRunStatus;
use App\Models\CatalogCandidate;
use App\Models\CatalogCandidateEvidence;
use App\Models\CatalogCandidateIngestionRun;
use App\Models\ImportRun;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportCatalogCandidatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_a_csv_file(): void
    {
        $this->artisan('catalog-candidates:import', [
            'file' => base_path('tests/Fixtures/catalog-candidates/valid.csv'),
        ])
            ->expectsOutputToContain('Ingestion run')
            ->expectsOutputToContain('Succeeded: 2')
            ->assertSuccessful();

        $this->assertSame(2, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateEvidence::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, ImportRun::query()->count());
        $this->assertSame(CatalogCandidateIngestionRunStatus::Completed, CatalogCandidateIngestionRun::query()->first()->status);
    }

    public function test_it_imports_a_json_file(): void
    {
        $this->artisan('catalog-candidates:import', [
            'file' => base_path('tests/Fixtures/catalog-candidates/valid.json'),
        ])
            ->expectsOutputToContain('Succeeded: 1')
            ->assertSuccessful();

        $this->assertSame(1, CatalogCandidate::query()->count());
        $this->assertSame(1, CatalogCandidateEvidence::query()->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('catalog-candidates:import', [
            'file' => base_path('tests/Fixtures/catalog-candidates/valid.json'),
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Dry run completed')
            ->expectsOutputToContain('Succeeded: 1')
            ->assertSuccessful();

        $this->assertSame(0, CatalogCandidate::query()->count());
        $this->assertSame(0, CatalogCandidateEvidence::query()->count());
        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
    }

    public function test_missing_file_fails_without_writing(): void
    {
        $this->artisan('catalog-candidates:import', [
            'file' => base_path('tests/Fixtures/catalog-candidates/missing.csv'),
        ])->assertFailed();

        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
        $this->assertSame(0, CatalogCandidate::query()->count());
    }

    public function test_unsupported_format_fails_without_writing(): void
    {
        $path = sys_get_temp_dir().'/catalog-candidates-'.uniqid().'.txt';
        file_put_contents($path, "title,source_type\nDesk Lamp,manual\n");

        try {
            $this->artisan('catalog-candidates:import', [
                'file' => $path,
            ])->assertFailed();

            $this->assertSame(0, CatalogCandidate::query()->count());
            $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_remote_urls_are_rejected(): void
    {
        $this->artisan('catalog-candidates:import', [
            'file' => 'https://example.com/candidates.json',
        ])->assertFailed();

        $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
    }

    public function test_malformed_json_fails_without_creating_a_run(): void
    {
        $path = sys_get_temp_dir().'/catalog-candidates-'.uniqid().'.json';
        file_put_contents($path, '{');

        try {
            $this->artisan('catalog-candidates:import', [
                'file' => $path,
            ])->assertFailed();

            $this->assertSame(0, CatalogCandidateIngestionRun::query()->count());
            $this->assertSame(0, CatalogCandidate::query()->count());
        } finally {
            @unlink($path);
        }
    }
}
