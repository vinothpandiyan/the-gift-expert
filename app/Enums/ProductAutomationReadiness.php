<?php

namespace App\Enums;

enum ProductAutomationReadiness: string
{
    case Ready = 'ready';
    case NeedsReview = 'needs_review';
    case Blocked = 'blocked';
}
