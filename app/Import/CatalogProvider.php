<?php

namespace App\Import;

use App\Models\Merchant;

interface CatalogProvider
{
    /**
     * @return iterable<int, ImportedCatalogItem>
     */
    public function eachProduct(Merchant $merchant): iterable;
}
