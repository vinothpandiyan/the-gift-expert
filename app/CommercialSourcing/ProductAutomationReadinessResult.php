<?php

namespace App\CommercialSourcing;

use App\Enums\ProductAutomationReadiness;

readonly class ProductAutomationReadinessResult
{
    /**
     * @param  list<string>  $exceptionCodes
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?ProductAutomationReadiness $readiness,
        public array $exceptionCodes,
        public array $warnings = [],
    ) {}
}
