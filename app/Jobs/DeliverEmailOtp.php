<?php

namespace App\Jobs;

use App\Mail\EmailOtp;
use App\Services\Auth\Client\ControlledAuth;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Payload у jobs/failed_jobs encrypted Laravel queue, жодних plaintext кодів. */
final class DeliverEmailOtp implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(private string $challenge, #[\SensitiveParameter] private string $email, #[\SensitiveParameter] private string $code) {}

    public function handle(ControlledAuth $auth): void
    {
        $auth->assert();
        $row = DB::table('auth_challenges as c')->join('auth_flows as f', 'f.id', '=', 'c.flow_id')
            ->where('c.public_id', $this->challenge)->whereNull('c.consumed_at')->whereNull('c.invalidated_at')
            ->where('f.state', 'pending')->whereColumn('c.generation', 'f.generation')
            ->whereRaw('c.expires_at > clock_timestamp() AND f.expires_at > clock_timestamp()')->exists();
        if ($row) {
            Mail::to($this->email)->send(new EmailOtp($this->code));
        }
    }

    public function __debugInfo(): array
    {
        return ['challenge' => $this->challenge, 'payload' => '[redacted]'];
    }
}
