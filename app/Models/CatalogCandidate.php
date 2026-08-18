<?php

namespace App\Models;

use App\Enums\CatalogCandidatePriority;
use App\Enums\CatalogCandidateSourceType;
use App\Enums\CatalogCandidateStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CatalogCandidate extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'title_fingerprint',
        'summary',
        'notes',
        'status',
        'priority',
        'source_type',
        'source_name',
        'source_url',
        'external_reference',
        'estimated_price_amount',
        'estimated_price_currency',
        'discovered_at',
        'last_evaluated_at',
        'reviewed_at',
        'created_by_user_id',
        'reviewed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogCandidateStatus::class,
            'priority' => CatalogCandidatePriority::class,
            'source_type' => CatalogCandidateSourceType::class,
            'estimated_price_amount' => 'decimal:2',
            'discovered_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(CatalogCandidateEvidence::class)
            ->orderBy('observed_at')
            ->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
