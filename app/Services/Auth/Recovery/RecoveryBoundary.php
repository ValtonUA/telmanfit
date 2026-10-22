<?php

namespace App\Services\Auth\Recovery;

/** D3/B4 port: production реалізації та service-container binding у B3 відсутні. */
interface RecoveryBoundary
{
    /** Поточна attestation registry/deployment/epoch, незалежна від DB snapshot; offline recheck без network під locks. */
    public function context(): array;

    /** Offline recheck custody/evidence receipt та freshness; admin approval включає fresh password+TOTP ≤5 хв. */
    public function receipt(object $case, array $approval): bool;

    /** Notice intent у тій самій DB connection/transaction; failure має кинути exception. */
    public function notice(object $case, string $event, string $request): void;

    /** Intake/close authority з transport; не довіряти actor ID із HTTP. */
    public function authorize(string $operation, object $case): bool;
}
