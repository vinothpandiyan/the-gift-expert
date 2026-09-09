<?php

namespace App\CuratedCatalog;

use RuntimeException;

class CuratedProductIntakeHardStopException extends RuntimeException
{
    /**
     * @param  list<array{code: string, message: string, details: list<array<string, mixed>>}>  $reasons
     */
    public function __construct(
        public array $reasons,
        string $message = 'Bulk curated intake refused: hard-stop conditions must be resolved first.',
    ) {
        parent::__construct($message);
    }
}
