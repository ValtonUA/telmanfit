<?php

namespace App\Services\Auth;

/** Внутрішній одноразовий результат перевірки, не bearer API credential. */
final class MfaProof implements \JsonSerializable
{
    private bool $claimed = false;

    public function __construct(
        public readonly int $user_id,
        public readonly string $audience,
        public readonly int $auth_version,
        public readonly string $primary_method,
        public readonly string $primary_verified_at,
        public readonly string $mfa_verified_at,
        public readonly string $mfa_method,
    ) {}

    public function claim(): void
    {
        if ($this->claimed) {
            throw new MfaDenied;
        }
        $this->claimed = true;
    }

    public function jsonSerialize(): mixed
    {
        return '[internal proof]';
    }

    public function __serialize(): array
    {
        throw new \LogicException('Proof serialization forbidden');
    }

    public function __clone(): void
    {
        throw new \LogicException('Proof cloning forbidden');
    }
}
