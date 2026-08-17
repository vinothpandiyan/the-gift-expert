<?php

namespace App\Import;

use App\Models\Merchant;
use RuntimeException;

class FakeCatalogProvider implements CatalogProvider
{
    public function eachProduct(Merchant $merchant): iterable
    {
        $path = (string) config('import.providers.'.$merchant->affiliate_network.'.fixture', '');

        if ($path === '' || ! is_readable($path)) {
            throw new RuntimeException('The fake catalog fixture could not be read.');
        }

        $contents = file_get_contents($path);

        if ($contents === false || $contents === '') {
            throw new RuntimeException('The fake catalog fixture could not be read.');
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded) || ! isset($decoded['products']) || ! is_array($decoded['products'])) {
            throw new RuntimeException('The fake catalog fixture is malformed.');
        }

        foreach (array_values($decoded['products']) as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('The fake catalog fixture is malformed.');
            }

            yield ImportedCatalogItem::fromRow($row);
        }
    }
}
