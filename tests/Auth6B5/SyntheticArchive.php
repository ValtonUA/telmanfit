<?php

namespace Tests\Auth6B5;

use App\Services\Auth\Retention\ArchiveVerification;

/** Object store поза restored DB; тільки synthetic receipt fixture, без production bind. */
final class SyntheticArchive implements ArchiveVerification
{
    public array $receipts = [];

    public function verify(object $manifest): bool
    {
        return app()->environment('testing') && ($this->receipts[$manifest->object_ref] ?? null) === $manifest->content_digest;
    }
}
