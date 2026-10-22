<?php

namespace App\Jobs;

use App\Mail\SecurityNotice;
use App\Services\Auth\Client\ControlledAuth;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

final class DeliverSecurityNotice implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(#[\SensitiveParameter] private string $email) {}

    public function handle(ControlledAuth $policy): void
    {
        $policy->assert();
        Mail::to($this->email)->send(new SecurityNotice);
    }

    public function __debugInfo(): array
    {
        return ['payload' => '[redacted]'];
    }
}
