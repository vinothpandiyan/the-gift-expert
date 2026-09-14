<?php

namespace App\Enums;

enum PublicationReadinessRemediation: string
{
    case ApproveStoredProposal = 'approve_stored_proposal';
    case HumanClassify = 'human_classify';
    case RetryClassification = 'retry_classification';
    case LeaveBlocked = 'leave_blocked';
}
