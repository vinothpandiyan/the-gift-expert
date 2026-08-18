<?php

namespace App\Models;

use App\Enums\CatalogCandidateSourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogCandidateEvidence extends Model
{
    use HasFactory;

    protected $table = 'catalog_candidate_evidence';

    protected $fillable = [
        'catalog_candidate_id',
        'source_type',
        'source_name',
        'source_url',
        'summary',
        'observed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => CatalogCandidateSourceType::class,
            'observed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(CatalogCandidate::class, 'catalog_candidate_id');
    }
}
