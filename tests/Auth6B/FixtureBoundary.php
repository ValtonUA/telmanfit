<?php

namespace Tests\Auth6B;

use App\Services\Auth\Recovery\RecoveryBoundary;

/** Лише синтетичний fixture; не evidence verifier і не B4 delivery guarantee. */
final class FixtureBoundary implements RecoveryBoundary
{
    public bool $failNotice = false;

    public bool $acceptReceipt = true;

    public array $current = ['deployment_id' => 'synthetic', 'restore_epoch' => '00000000-0000-4000-8000-000000000001', 'registry_version' => 1];

    public function context(): array
    {
        return $this->current;
    }

    public function receipt(object $case, array $approval): bool
    {
        return $this->acceptReceipt;
    }

    public function notice(object $case, string $event, string $request): void
    {
        if ($this->failNotice) {
            throw new \RuntimeException('Synthetic notice failure');
        }
    }

    public function authorize(string $operation, object $case): bool
    {
        return true;
    }
}
