<?php

namespace App\Enums;

enum CuratedProductIntakeSourceType: string
{
    case BrowserJson = 'browser_json';
    case CliJson = 'cli_json';
}
