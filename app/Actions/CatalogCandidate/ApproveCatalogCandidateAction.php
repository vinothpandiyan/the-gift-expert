<?php

namespace App\Actions\CatalogCandidate;

use App\Enums\CatalogCandidateStatus;
use App\Models\CatalogCandidate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveCatalogCandidateAction
{
    public function execute(CatalogCandidate $candidate, User|int|null $reviewedBy = null): CatalogCandidate
    {
        return DB::transaction(function () use ($candidate, $reviewedBy): CatalogCandidate {
            $locked = $this->lock($candidate);

            if ($locked->status !== CatalogCandidateStatus::UnderReview) {
                throw ValidationException::withMessages([
                    'status' => [
                        "This catalog candidate cannot be approved from its current status ({$locked->status->value}).",
                    ],
                ]);
            }

            $locked->status = CatalogCandidateStatus::Approved;
            $locked->last_evaluated_at = now();
            $locked->reviewed_at = now();
            $locked->reviewed_by_user_id = $this->userId($reviewedBy);
            $locked->save();

            return $locked;
        });
    }

    private function lock(CatalogCandidate $candidate): CatalogCandidate
    {
        $locked = CatalogCandidate::query()->whereKey($candidate->id)->lockForUpdate()->first();

        if (! $locked instanceof CatalogCandidate) {
            throw ValidationException::withMessages([
                'status' => ['A deleted catalog candidate cannot be approved.'],
            ]);
        }

        return $locked;
    }

    private function userId(User|int|null $reviewedBy): ?int
    {
        return $reviewedBy instanceof User ? $reviewedBy->id : $reviewedBy;
    }
}
