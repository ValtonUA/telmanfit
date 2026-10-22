<?php

namespace App\Services\Auth\Restore;

/** Лише ізольовані tests: instance поза snapshot БД; fault injection без providers. */
final class SyntheticEpoch implements ExternalEpoch
{
    public const INITIAL = '60605050-0000-4000-8000-000000000001';

    public function __construct(public ?string $epoch = self::INITIAL) {}

    public function current(): string
    {
        if (! app()->environment('testing') || $this->epoch === null) {
            throw new \RuntimeException('external_epoch_unavailable');
        }

        return $this->epoch;
    }
}
