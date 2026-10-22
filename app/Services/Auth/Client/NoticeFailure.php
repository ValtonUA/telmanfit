<?php

namespace App\Services\Auth\Client;

use App\Support\Auth\AuditOutcome;
use Illuminate\Support\Facades\Log;

final class NoticeFailure
{
    public function record(int $subject, string $correlation): void
    {
        $audit = 'recorded';
        try {
            app(ControlledAuth::class)->transaction(fn () => app(ControlledAuth::class)
                ->audit('client.notice_failed', $subject, AuditOutcome::Error, $correlation));
        } catch (\Throwable) {
            $audit = 'failed';
        }
        // Незалежний канал для audit outage. Жодного exception/payload/email.
        Log::error('client.notice_failed', ['request_id' => $correlation, 'subject_user_id' => $subject, 'audit' => $audit]);
    }
}
