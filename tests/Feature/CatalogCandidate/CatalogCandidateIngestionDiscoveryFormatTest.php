<?php

namespace Tests\Feature\CatalogCandidate;

use App\Enums\CatalogCandidateIngestionFormat;
use App\Enums\CatalogCandidateIngestionRunStatus;
use App\Models\CatalogCandidateIngestionRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogCandidateIngestionDiscoveryFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingestion_runs_accept_the_discovery_format(): void
    {
        $run = CatalogCandidateIngestionRun::query()->create([
            'format' => CatalogCandidateIngestionFormat::Discovery,
            'source_name' => 'discovery:fake',
            'status' => CatalogCandidateIngestionRunStatus::Completed,
            'started_at' => now(),
            'finished_at' => now(),
            'items_total' => 0,
        ]);

        $this->assertSame(CatalogCandidateIngestionFormat::Discovery, $run->fresh()->format);
        $this->assertSame('discovery', $run->fresh()->format->value);
    }
}
