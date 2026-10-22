<?php

namespace App\Jobs;

use App\Mail\SecurityNotice;
use App\Services\Auth\Client\ControlledAuth;
use App\Services\Auth\Client\NoticeDeliveryFailed;
use App\Services\Auth\Client\NoticeFailure;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

final class DeliverSecurityNotice implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(#[\SensitiveParameter] private string $email, private int $subject, private string $correlation) {}

    public function handle(ControlledAuth $policy): void
    {
        try {
            $policy->assert();
            Mail::to($this->email)->send(new SecurityNotice);
        } catch (\Throwable) {
            app(NoticeFailure::class)->record($this->subject, $this->correlation);
            // Без previous exception: failed_jobs не зберігає SMTP payload/адреси.
            throw new NoticeDeliveryFailed;
        }
    }

    public function __debugInfo(): array
    {
        return ['payload' => '[redacted]'];
    }
}
