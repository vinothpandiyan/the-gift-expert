<?php

namespace App\Models;

use App\Enums\CatalogRole;
use App\Enums\ProductCurationDecision as ProductCurationDecisionValue;
use App\Enums\ProductCurationDecisionReasonCode;
use App\Enums\ProductCurationRemediationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductCurationDecision extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'decision' => ProductCurationDecisionValue::class,
            'reason_codes' => 'array',
            'catalog_role' => CatalogRole::class,
            'taxonomy_proposal' => 'array',
            'remediation_status' => ProductCurationRemediationStatus::class,
            'remediation_manifest' => 'array',
            'decided_at' => 'datetime',
            'remediated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $decision): void {
            $decision->uuid ??= (string) Str::uuid();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceAuditRun(): BelongsTo
    {
        return $this->belongsTo(ProductCurationAuditRun::class, 'source_audit_run_id');
    }

    public function sourceAudit(): BelongsTo
    {
        return $this->belongsTo(ProductCurationAudit::class, 'source_product_curation_audit_id');
    }

    public function previousDecision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_decision_id');
    }

    public function succeedingDecisions(): HasMany
    {
        return $this->hasMany(self::class, 'previous_decision_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function remediatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remediated_by_user_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNotNull('current_for_product_id');
    }

    public function isCurrent(): bool
    {
        return $this->current_for_product_id !== null;
    }

    public function isHumanReviewed(): bool
    {
        if (! $this->decision instanceof ProductCurationDecisionValue || ! $this->decision->isResolved()) {
            return false;
        }

        if ($this->decision === ProductCurationDecisionValue::RemoveCandidate) {
            return true;
        }

        if ($this->decision->requiresRemediation()) {
            return $this->remediation_status === ProductCurationRemediationStatus::Completed;
        }

        return true;
    }

    /**
     * @return list<ProductCurationDecisionReasonCode>
     */
    public function reasonCodeEnums(): array
    {
        return ProductCurationDecisionReasonCode::fromValues($this->reason_codes);
    }
}
