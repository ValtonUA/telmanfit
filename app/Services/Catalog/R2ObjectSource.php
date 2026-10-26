<?php

namespace App\Services\Catalog;

interface R2ObjectSource
{
    /** @return list<array{key: string}> */
    public function list(): array;
}
