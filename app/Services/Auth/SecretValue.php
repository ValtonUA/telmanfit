<?php

namespace App\Services\Auth;

/** Явне одноразове розкриття лише службовому HTML; жодної JSON/debug серіалізації. */
final class SecretValue implements \JsonSerializable
{
    public function __construct(#[\SensitiveParameter] private readonly string|array $value) {}

    public function reveal(): string|array
    {
        return $this->value;
    }

    public function jsonSerialize(): mixed
    {
        return '[redacted]';
    }

    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Secret serialization forbidden');
    }
}
