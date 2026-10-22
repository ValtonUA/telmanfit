<?php

namespace App\Console\Commands;

use App\Services\Auth\Notices\NoticeMetrics;
use Illuminate\Console\Command;

final class SecurityNoticeMetrics extends Command
{
    protected $signature = 'auth:notices-metrics';

    protected $description = 'Агреговані outbox metrics для незалежного monitor';

    public function handle(NoticeMetrics $metrics): int
    {
        try {
            $snapshot = $metrics->snapshot();
            $this->line(json_encode($snapshot, JSON_THROW_ON_ERROR));

            return $snapshot['alarm'] ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            $this->error('security_notice_monitor_unavailable');

            return self::FAILURE;
        }
    }
}
