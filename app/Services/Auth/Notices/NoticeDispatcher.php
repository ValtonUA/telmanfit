<?php

namespace App\Services\Auth\Notices;

use App\Services\Auth\SecretValue;
use Illuminate\Support\Facades\DB;

final class NoticeDispatcher
{
    public function claim(int $batch = 1): array
    {
        $this->assertAvailable();
        if (DB::transactionLevel() !== 0 || $batch < 1 || $batch > 50) {
            throw new NoticeUnavailable;
        }

        return $this->transaction(fn () => DB::select('SELECT * FROM auth_notice_claim(?)', [$batch]));
    }

    public function acknowledge(string $id, string $token, string $result): bool
    {
        $this->assertAvailable();
        if (DB::transactionLevel() !== 0) {
            throw new NoticeUnavailable;
        }

        return (bool) $this->transaction(fn () => DB::selectOne('SELECT auth_notice_ack(?::uuid,?::uuid,?) AS ok', [$id, $token, $result])->ok);
    }

    public function deliver(object $claim): bool
    {
        $this->assertAvailable();
        if (DB::transactionLevel() !== 0) {
            throw new NoticeUnavailable;
        }
        // Recheck актуального lease/expiry перед зовнішнім side effect.
        $row = $this->transaction(fn () => DB::table('auth_security_notice_outbox')->where('id', $claim->id)->where('state', 'leased')
            ->where('lease_token', $claim->lease_token)->whereRaw('lease_until>clock_timestamp() AND expires_at>clock_timestamp()')->first());
        if (! $row) {
            return $this->acknowledge($claim->id, $claim->lease_token, 'sent');
        }
        try {
            $recipient = new SecretValue(app(RecipientCodec::class)->decrypt($row->recipient_ciphertext, $row->recipient_key_version));
        } catch (\Throwable) {
            return $this->acknowledge($row->id, $row->lease_token, 'key');
        }
        try {
            $adapter = $row->recipient_slot === 'independent' ? IndependentNoticeDelivery::class : NoticeDelivery::class;
            if (! app()->bound($adapter)) {
                $result = DeliveryResult::Policy;
            } else {
                $started = hrtime(true);
                $result = app($adapter)->send($recipient, NoticeType::from($row->notice_type), $row->created_at, '<'.$row->id.'@security.telmanfit.invalid>', 10);
                if (hrtime(true) - $started > 10_000_000_000) {
                    $result = DeliveryResult::Timeout;
                }
            }
        } catch (\Throwable) {
            $result = DeliveryResult::Transient;
        }

        return $this->acknowledge($row->id, $row->lease_token, $result->value);
    }

    /** Один claim/send за раз: не накопичувати 50 десятисекундних sends у 90s lease. */
    public function poll(int $limit = 50): int
    {
        $started = hrtime(true);
        $count = 0;
        while ($count < min(50, max(1, $limit)) && hrtime(true) - $started < 9_000_000_000) {
            $claims = $this->claim();
            if ($claims === []) {
                break;
            }
            $this->deliver($claims[0]);
            $count++;
        }

        return $count;
    }

    private function transaction(callable $callback): mixed
    {
        try {
            return DB::transaction(function () use ($callback) {
                DB::statement("SET LOCAL lock_timeout = '2s'");
                DB::statement("SET LOCAL statement_timeout = '2s'");

                return $callback();
            });
        } catch (\Throwable) {
            throw new NoticeUnavailable;
        }
    }

    private function assertAvailable(): void
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDriverName() !== 'pgsql') {
            throw new NoticeUnavailable;
        }
    }
}
