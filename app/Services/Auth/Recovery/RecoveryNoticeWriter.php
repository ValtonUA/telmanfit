<?php

namespace App\Services\Auth\Recovery;

use App\Services\Auth\Notices\NoticeType;
use App\Services\Auth\Notices\NoticeWriter;
use Illuminate\Support\Facades\DB;

/** B4 реалізація notice intent; D3 authority/незалежний канал лишаються окремими ports. */
final class RecoveryNoticeWriter
{
    public function notice(object $case, string $event, string $request): void
    {
        $type = match ($event) {
            'created' => NoticeType::RecoveryStarted,
            'approved' => NoticeType::RecoveryApproved,
            'quarantined' => NoticeType::CredentialsRevoked,
            'completed' => NoticeType::RecoveryCompleted,
            'cancelled', 'rejected', 'expired' => NoticeType::RecoveryCancelled,
            default => null,
        };
        if ($type === null) {
            return;
        }
        $recipients = ['current' => DB::table('users')->where('id', $case->target_user_id)->value('email')];
        if ($event === 'completed' && $case->action === 'replace_primary_mfa') {
            $recipients['old'] = $case->noticeOldEmail;
        }
        app(NoticeWriter::class)->write($case->target_user_id, $type, $recipients, $case->id, $request);
    }
}
