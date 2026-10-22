<?php

namespace App\Services\Auth\Notices;

use Illuminate\Support\Facades\DB;

final class NoticeMetrics
{
    /** Лише агрегати; без subject, request, destination, provider response. */
    public function snapshot(): array
    {
        $row = DB::selectOne("SELECT count(*) FILTER (WHERE state IN ('pending','leased')) AS backlog,
            count(*) FILTER (WHERE attempts>1) AS retries,
            count(*) FILTER (WHERE state='dead') AS dead,
            count(*) FILTER (WHERE state='expired') AS expired,
            count(*) FILTER (WHERE state='leased' AND lease_until<=clock_timestamp()) AS stale_leases,
            count(*) FILTER (WHERE last_error_code='key') AS key_failures,
            coalesce(max(extract(epoch FROM clock_timestamp()-created_at)) FILTER (WHERE state='pending'),0) AS oldest_pending_seconds
            FROM auth_security_notice_outbox");
        $metrics = array_map(fn ($v) => (float) $v, (array) $row);
        $last = DB::selectOne("SELECT extract(epoch FROM clock_timestamp()-max(occurred_at)) AS seconds FROM auth_audit_events WHERE event_type='notice.claimed'");
        $metrics['heartbeat_age_seconds'] = $last->seconds === null ? null : (float) $last->seconds;
        $metrics['alarm'] = $metrics['oldest_pending_seconds'] > 330 || $metrics['dead'] > 0 || $metrics['expired'] > 0
            || $metrics['key_failures'] > 0 || $metrics['stale_leases'] > 0
            || ($metrics['backlog'] > 0 && ($metrics['heartbeat_age_seconds'] === null || $metrics['heartbeat_age_seconds'] > 120));

        return $metrics;
    }
}
