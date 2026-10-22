<?php

namespace App\Services\Auth\Budgets;

/** Закрита серверна policy; HTTP не передає limit/window. */
enum BudgetScope: string
{
    case OtpCooldown = 'otp_cooldown';
    case OtpEmailShort = 'otp_email_short';
    case OtpEmailDay = 'otp_email_day';
    case OtpEmailAll = 'otp_email_all';
    case OtpIpShort = 'otp_ip_short';
    case OtpIpDay = 'otp_ip_day';
    case OtpGlobal = 'otp_global';
    case OtpVerifyIp = 'otp_verify_ip';
    case OtpFailure = 'otp_failure';
    case InvitationIp = 'invitation_ip';
    case InvitationToken = 'invitation_token';
    case RegistrationIp = 'registration_ip';
    case RegistrationFlow = 'registration_flow';
    case MfaIp = 'mfa_ip';
    case MfaFlow = 'mfa_flow';
    case MfaUser = 'mfa_user';
    case AdminEmail = 'admin_email';
    case AdminIp = 'admin_ip';
    case StepupIp = 'stepup_ip';
    case StepupUser = 'stepup_user';
    case RecoveryExchangeIp = 'recovery_exchange_ip';
    case RecoveryExchangeCase = 'recovery_exchange_case';
    case RecoveryTotpFlow = 'recovery_totp_flow';
    case RecoveryTotpUser = 'recovery_totp_user';
    case RecoveryTotpIp = 'recovery_totp_ip';
}
