<?php

namespace App\Enums;

enum ProductCurationAuditOutcome: string
{
    case Pending = 'pending';
    case SemanticReady = 'semantic_ready';
    case Completed = 'completed';
    case Failed = 'failed';
}
