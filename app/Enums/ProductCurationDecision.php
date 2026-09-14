<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProductCurationDecision: string implements HasColor, HasLabel
{
    case Keep = 'keep';
    case Feature = 'feature';
    case KeepNiche = 'keep_niche';
    case Reclassify = 'reclassify';
    case Deactivate = 'deactivate';
    case RemoveCandidate = 'remove_candidate';
    case Defer = 'defer';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Keep => 'Keep',
            self::Feature => 'Feature',
            self::KeepNiche => 'Keep niche',
            self::Reclassify => 'Reclassify',
            self::Deactivate => 'Deactivate',
            self::RemoveCandidate => 'Remove candidate',
            self::Defer => 'Defer',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Keep, self::Feature, self::KeepNiche => 'success',
            self::Reclassify => 'warning',
            self::Deactivate, self::RemoveCandidate => 'danger',
            self::Defer => 'gray',
        };
    }

    public function isResolved(): bool
    {
        return $this !== self::Defer;
    }

    public function isKeepFamily(): bool
    {
        return in_array($this, [self::Keep, self::Feature, self::KeepNiche], true);
    }

    public function allowsCatalogRole(): bool
    {
        return $this->isKeepFamily();
    }

    public function requiresRemediation(): bool
    {
        return in_array($this, [self::Reclassify, self::Deactivate, self::RemoveCandidate], true);
    }

    public function requiresExplanation(): bool
    {
        return in_array($this, [
            self::Reclassify,
            self::Deactivate,
            self::RemoveCandidate,
            self::Defer,
        ], true);
    }

    public function operatorConsequence(): string
    {
        return match ($this) {
            self::Deactivate => 'This records a DEACTIVATE decision and archives the Product so it leaves the public catalog. It will NOT delete the Product, change taxonomy, or alter the accepted audit.',
            self::Reclassify => 'This records a RECLASSIFY decision with a structured taxonomy proposal and leaves remediation pending. Taxonomy changes only when Execute remediation succeeds. It will NOT publish, unpublish, archive, delete the Product, or alter the accepted audit.',
            self::RemoveCandidate => 'This records a REMOVE CANDIDATE intention only. It will NOT archive or delete the Product, change taxonomy, or alter the accepted audit.',
            self::Defer => 'This records a DEFER decision. The gift stays in the review queue. The catalog and accepted audit are not changed.',
            default => 'This records a human curation decision. It will NOT delete the Product, change taxonomy, publish or unpublish, or alter the audit.',
        };
    }

    public static function defaultOperatorConsequence(): string
    {
        return 'This records a human curation decision. KEEP / FEATURE / KEEP_NICHE / DEFER / REMOVE CANDIDATE do not mutate the catalog. RECLASSIFY stores a structured taxonomy proposal and stays pending until Execute remediation. DEACTIVATE archives the Product after the decision is saved. The accepted audit is never altered.';
    }
}
