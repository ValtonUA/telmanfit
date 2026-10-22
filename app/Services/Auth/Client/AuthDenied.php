<?php

namespace App\Services\Auth\Client;

final class AuthDenied extends \RuntimeException
{
    public function __construct(public readonly string $reason = 'invalid_challenge', public readonly int $status = 422)
    {
        parent::__construct($reason);
    }
}
