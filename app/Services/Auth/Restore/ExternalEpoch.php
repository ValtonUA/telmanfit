<?php

namespace App\Services\Auth\Restore;

/** Актуальний незалежний control record; жодних DB/.env fallback. */
interface ExternalEpoch
{
    public function current(): string;
}
