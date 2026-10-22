<?php

namespace App\Services\Auth\Notices;

enum NoticeType: string
{
    case EmailChanged = 'email_changed';
    case RecoveryStarted = 'recovery_started';
    case RecoveryApproved = 'recovery_approved';
    case RecoveryCancelled = 'recovery_cancelled';
    case RecoveryCompleted = 'recovery_completed';
    case CredentialsRevoked = 'credentials_revoked';
    case MfaChanged = 'mfa_changed';

    public function template(): string
    {
        return match ($this) {
            self::EmailChanged => 'Змінено адресу входу.',
            self::RecoveryStarted => 'Розпочато відновлення доступу.',
            self::RecoveryApproved => 'Підтверджено етап відновлення доступу.',
            self::RecoveryCancelled => 'Відновлення доступу закрито.',
            self::RecoveryCompleted => 'Відновлення доступу завершено.',
            self::CredentialsRevoked => 'Доступ відкликано.',
            self::MfaChanged => 'Змінено налаштування захисту входу.',
        };
    }
}
