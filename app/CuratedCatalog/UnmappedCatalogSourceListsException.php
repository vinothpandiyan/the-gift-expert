<?php

namespace App\CuratedCatalog;

use RuntimeException;

class UnmappedCatalogSourceListsException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $unmappedLists
     */
    public function __construct(
        public array $unmappedLists,
        string $message = 'Bulk curated intake refused: one or more source lists are unmapped.',
    ) {
        parent::__construct($message);
    }
}
