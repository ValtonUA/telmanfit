<?php

namespace App\Jobs;

use App\Services\Auth\Notices\NoticeDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Необов'язковий сигнал без PII. Втрачений сигнал покриває auth:notices-dispatch polling. */
final class PollSecurityNotices implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function handle(NoticeDispatcher $dispatcher): void
    {
        try {
            $dispatcher->poll();
        } catch (\Throwable) {
            // Durable rows збережено; logger/failed_jobs не отримують DB bindings.
            throw new \RuntimeException('security_notice_unavailable');
        }
    }
}
