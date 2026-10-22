<?php

namespace App\Services\Auth\Client;

use App\Services\Auth\MfaPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ControlledAuth
{
    public function assert(): void
    {
        if (! in_array(config('auth4.enabled'), [true, 'true', '1'], true) || ! app()->environment(['local', 'testing'])
            || config('database.default') !== 'pgsql' || config('session.driver') !== 'database'
            || config('cache.default') !== 'database'
            || config('cache.stores.database.connection') !== null
            || ! in_array(config('queue.default'), ['sync', 'database'], true)
            || ! in_array(config('mail.default'), ['array', 'auth4_synthetic'], true)) {
            throw new AuthDenied('auth_unavailable', 503);
        }
        try {
            app(MfaPolicy::class)->assertControlled();
        } catch (\Throwable) {
            throw new AuthDenied('auth_unavailable', 503);
        }
        $this->key((string) config('auth4.key_version'));
        if (! is_string(config('auth4.rate_key')) || strlen(config('auth4.rate_key')) < 32) {
            throw new AuthDenied('auth_unavailable', 503);
        }
        // Недоступність central rate store закриває також чинні protected sessions.
        Cache::store('database')->get('auth4:availability-probe');
    }

    public function key(string $version): string
    {
        $key = config('auth4.keys.'.$version);
        if (! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $version) || ! is_string($key) || strlen($key) < 32) {
            throw new AuthDenied('auth_unavailable', 503);
        }

        return $key;
    }

    public function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('auth4.rate_key'));
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS t')->t);
    }

    public function stamp(): string
    {
        return $this->now()->format('Y-m-d H:i:s.uP');
    }

    /** Контрольований стенд: один bounded PG lock, без network під lock. */
    public function transaction(callable $callback): mixed
    {
        $this->assert();

        return DB::transaction(function () use ($callback) {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::select('SELECT pg_advisory_xact_lock(4040404)');

            return $callback();
        });
    }

    public function audit(string $event, ?int $user = null): void
    {
        DB::table('auth_audit_events')->insert(['event_type' => $event, 'outcome' => 'success',
            'subject_user_id' => $user, 'request_id' => (string) Str::uuid()]);
    }
}
