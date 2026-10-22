<?php

namespace Tests\Auth6B5;

use App\Services\Auth\Retention\RetentionService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OperatorTest extends TestCase
{
    public function test_failed_batch_rolls_back_then_records_sanitized_durable_run_failure(): void
    {
        require __DIR__.'/../pg/auth2-guard.php';
        $service = app(RetentionService::class);
        DB::statement('SET ROLE telmanfit_retention_executor');
        try {
            $run = $service->start();
            try {
                $service->cleanup($run, 1, 'arbitrary_sql_forbidden');
                $this->fail('Unknown entity accepted');
            } catch (\RuntimeException $e) {
                $this->assertSame('retention_unavailable', $e->getMessage());
                $this->assertNull($e->getPrevious());
            }
            $this->assertSame('failed', DB::table('auth_retention_runs')->where('id', $run)->value('state'));
            $this->assertSame(0, DB::table('auth_retention_batches')->where('run_id', $run)->count());
        } finally {
            DB::statement('RESET ROLE');
        }
        $this->assertTrue(DB::table('auth_audit_events')->where('request_id', $run)->where('event_type', 'retention.run_failed')->where('outcome', 'error')->exists());
    }
}
