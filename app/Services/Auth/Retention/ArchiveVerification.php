<?php

namespace App\Services\Auth\Retention;

/** Незалежна перевірка доступного immutable object, digest, bounds і custody receipt. */
interface ArchiveVerification
{
    public function verify(object $manifest): bool;
}
