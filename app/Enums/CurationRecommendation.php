<?php

namespace App\Enums;

enum CurationRecommendation: string
{
    case Feature = 'feature';
    case Keep = 'keep';
    case KeepNiche = 'keep_niche';
    case Review = 'review';
    case ReplaceCandidate = 'replace_candidate';
    case RemoveCandidate = 'remove_candidate';

    public function isCandidate(): bool
    {
        return in_array($this, [self::ReplaceCandidate, self::RemoveCandidate], true);
    }
}
