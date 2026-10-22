<?php

namespace App\Services\Auth\Budgets;

/** Вузький bridge: невідомий key або змінені legacy numbers → closed. */
final class LegacyBudgetMap
{
    public static function key(#[\SensitiveParameter] string $key, int $limit, int $seconds): BudgetKey
    {
        $rules = [
            ['cooldown:', BudgetScope::OtpCooldown, 1, 60],
            ['issue:15:', BudgetScope::OtpEmailShort, 3, 900],
            ['issue:day:', BudgetScope::OtpEmailDay, 10, 86400],
            ['issue:all:', BudgetScope::OtpEmailAll, 15, 86400],
            ['issue:ip:15:', BudgetScope::OtpIpShort, 20, 900],
            ['issue:ip:day:', BudgetScope::OtpIpDay, 100, 86400],
            ['verify:ip:', BudgetScope::OtpVerifyIp, 10, 60],
            ['exchange:ip:', BudgetScope::InvitationIp, 10, 60],
            ['exchange:token:', BudgetScope::InvitationToken, 5, 900],
            ['complete:ip:', BudgetScope::RegistrationIp, 10, 60],
            ['complete:flow:', BudgetScope::RegistrationFlow, 5, 900],
            ['mfa:ip:', BudgetScope::MfaIp, 20, 900],
            ['invitation-stepup:ip:', BudgetScope::StepupIp, 20, 900],
            ['invitation-stepup:user:', BudgetScope::StepupUser, 5, 900],
            ['recovery.exchange:ip:', BudgetScope::RecoveryExchangeIp, 10, 60],
            ['recovery.exchange:case:', BudgetScope::RecoveryExchangeCase, 5, 900],
            ['recovery.totp:flow:', BudgetScope::RecoveryTotpFlow, 5, 900],
            ['recovery.totp:user:', BudgetScope::RecoveryTotpUser, 10, 900],
            ['recovery.totp:ip:', BudgetScope::RecoveryTotpIp, 20, 900],
        ];
        if ($key === 'issue:global' && $limit === 1000 && $seconds === 3600) {
            return new BudgetKey(BudgetScope::OtpGlobal, 'global');
        }
        foreach ($rules as [$prefix, $scope, $cap, $window]) {
            if (str_starts_with($key, $prefix) && strlen($key) > strlen($prefix) && $limit === $cap && $seconds === $window) {
                return new BudgetKey($scope, substr($key, strlen($prefix)));
            }
        }
        throw new BudgetUnavailable;
    }
}
