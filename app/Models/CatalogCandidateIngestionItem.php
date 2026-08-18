<?php

namespace App\Models;

use App\Enums\CatalogCandidateIngestionItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogCandidateIngestionItem extends Model
{
    protected $fillable = [
        'catalog_candidate_ingestion_run_id',
        'item_index',
        'title',
        'catalog_candidate_id',
        'status',
        'error',
        'source_payload',
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogCandidateIngestionItemStatus::class,
            'source_payload' => 'array',
            'item_index' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CatalogCandidateIngestionRun::class, 'catalog_candidate_ingestion_run_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(CatalogCandidate::class);
    }
}
