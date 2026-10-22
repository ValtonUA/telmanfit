<?php

namespace App\Console\Commands;

use App\Services\Auth\Notices\NoticeDispatcher;
use Illuminate\Console\Command;

final class DispatchSecurityNotices extends Command
{
    protected $signature = 'auth:notices-dispatch';

    protected $description = 'Один bounded synthetic outbox polling pass; production закрито';

    public function handle(NoticeDispatcher $dispatcher): int
    {
        try {
            $this->line('processed='.$dispatcher->poll());

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('security_notice_unavailable');

            return self::FAILURE;
        }
    }
}
