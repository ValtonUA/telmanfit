<?php

namespace Tests\Auth6B5;

use App\Services\Auth\Retention\ArchiveVerification;

/** Synthetic object bytes поза DB; немає production bind або довіри до manifest digest. */
final class SyntheticArchive implements ArchiveVerification
{
    public array $objects = [];

    public function verify(object $manifest): bool
    {
        $bytes = $this->objects[$manifest->object_ref] ?? null;

        return app()->environment('testing') && is_string($bytes)
            && hash_equals($manifest->content_digest, hash('sha256', $bytes));
    }
}
