<?php

namespace App\Models;

use App\Enums\CatalogCandidateDiscoveryRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogCandidateDiscoveryRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_key',
        'brief',
        'market',
        'max_candidates',
        'freshness_days',
        'status',
        'queries',
        'retrieved_urls',
        'candidates_proposed',
        'catalog_candidate_ingestion_run_id',
        'started_at',
        'finished_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogCandidateDiscoveryRunStatus::class,
            'queries' => 'array',
            'retrieved_urls' => 'array',
            'max_candidates' => 'integer',
            'freshness_days' => 'integer',
            'candidates_proposed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function ingestionRun(): BelongsTo
    {
        return $this->belongsTo(CatalogCandidateIngestionRun::class, 'catalog_candidate_ingestion_run_id');
    }
}
