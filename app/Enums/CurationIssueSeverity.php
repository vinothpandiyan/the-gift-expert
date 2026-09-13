<?php

namespace App\Enums;

enum CurationIssueSeverity: string
{
    case Info = 'info';
    case Advisory = 'advisory';
    case Warning = 'warning';
    case Material = 'material';
    case Critical = 'critical';
    case Blocking = 'blocking';
}
