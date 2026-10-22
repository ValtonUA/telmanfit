<?php

namespace App\Services\Auth\Notices;

use App\Services\Auth\Restore\RestoreFence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NoticeWriter
{
    /** Виклик тільки всередині security transaction на default PG connection. */
    public function write(int $subject, NoticeType $type, #[\SensitiveParameter] array $recipients, ?string $case = null, ?string $request = null): string
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || DB::transactionLevel() < 1 || ! app()->environment(['local', 'testing'])) {
            throw new NoticeUnavailable;
        }
        try {
            app(RestoreFence::class)->assert();
        } catch (\Throwable) {
            throw new NoticeUnavailable;
        }
        $request ??= (string) Str::uuid();
        if (! Str::isUuid($request) || ($case !== null && ! Str::isUuid($case)) || $recipients === []) {
            throw new NoticeUnavailable;
        }
        try {
            // Серіалізуємо повтори того самого server UUID, включно з missing rows.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 64004))', [$request]);
            $existing = DB::table('auth_security_notice_outbox')->where('request_id', $request)->orderBy('recipient_slot')->get();
            $slots = array_keys($recipients);
            sort($slots);
            if ($existing->isNotEmpty()) {
                if ($existing->pluck('recipient_slot')->all() !== $slots) {
                    throw new NoticeUnavailable;
                }
                foreach ($existing as $row) {
                    if ($row->subject_user_id != $subject || $row->notice_type !== $type->value || $row->case_reference !== $case || $row->contract_version !== 1
                        || $row->recipient_ciphertext === null
                        || ! hash_equals(app(RecipientCodec::class)->decrypt($row->recipient_ciphertext, $row->recipient_key_version), $recipients[$row->recipient_slot])) {
                        throw new NoticeUnavailable;
                    }
                }

                return $request;
            }
            $version = (string) config('auth_notices.key_version');
            if (! preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D', $version)) {
                throw new NoticeUnavailable;
            }
            foreach ($recipients as $slot => $recipient) {
                if (! RecipientSlot::tryFrom($slot) || ! is_string($recipient) || $recipient === '' || strlen($recipient) > 320
                    || ($slot !== 'independent' && ! filter_var($recipient, FILTER_VALIDATE_EMAIL))
                    || ($slot === 'old' && ! in_array($type, [NoticeType::EmailChanged, NoticeType::RecoveryCompleted], true))) {
                    throw new NoticeUnavailable;
                }
                DB::table('auth_security_notice_outbox')->insert([
                    'id' => (string) Str::uuid(), 'request_id' => $request, 'subject_user_id' => $subject,
                    'case_reference' => $case, 'notice_type' => $type->value, 'contract_version' => 1, 'recipient_slot' => $slot,
                    'recipient_ciphertext' => app(RecipientCodec::class)->encrypt($recipient, $version), 'recipient_key_version' => $version,
                    'created_at' => DB::raw('statement_timestamp()'), 'expires_at' => DB::raw("statement_timestamp()+interval '24 hours'"),
                    'next_attempt_at' => DB::raw('statement_timestamp()'),
                ]);
            }

            return $request;
        } catch (\Throwable) {
            // Немає previous exception: SQL bindings можуть містити PII.
            throw new NoticeUnavailable;
        }
    }
}
