<?php

namespace App\Enums;

enum CatalogCandidateStatus: string
{
    case Discovered = 'discovered';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
