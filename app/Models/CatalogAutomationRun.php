<?php

namespace App\Models;

use App\Enums\CatalogAutomationRunStatus;
use App\Enums\CatalogAutomationStage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogAutomationRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'brief',
        'market',
        'max_candidates',
        'freshness_days',
        'status',
        'current_stage',
        'started_at',
        'finished_at',
        'discovery_run_id',
        'sourcing_run_id',
        'counts',
        'error',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogAutomationRunStatus::class,
            'current_stage' => CatalogAutomationStage::class,
            'max_candidates' => 'integer',
            'freshness_days' => 'integer',
            'counts' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function discoveryRun(): BelongsTo
    {
        return $this->belongsTo(CatalogCandidateDiscoveryRun::class, 'discovery_run_id');
    }

    public function sourcingRun(): BelongsTo
    {
        return $this->belongsTo(CatalogCandidateSourcingRun::class, 'sourcing_run_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
