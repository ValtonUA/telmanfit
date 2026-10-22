<?php

namespace Tests\Auth6B5;

use App\Services\Auth\Retention\RetentionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function test_repeatable_read_cannot_apply_cleanup_using_a_stale_hold_snapshot(): void
    {
        require __DIR__.'/../pg/auth2-guard.php';
        $service = app(RetentionService::class);
        $run = $service->start();
        $job = DB::table('failed_jobs')->insertGetId(['uuid' => (string) Str::uuid(), 'connection' => 'synthetic', 'queue' => 'auth',
            'payload' => 'synthetic-old-payload', 'exception' => 'synthetic', 'failed_at' => DB::raw("clock_timestamp()-interval '25 hours'")]);
        DB::beginTransaction();
        try {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::statement('SET LOCAL ROLE telmanfit_retention_executor');
            try {
                DB::select("SELECT auth_retention_cleanup(?::uuid,1,'failed_jobs')", [$run]);
                $this->fail('Stale transaction snapshot permitted cleanup');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0]);
                $this->assertStringContainsString('Read committed retention required', $e->getMessage());
            }
        } finally {
            DB::rollBack();
        }
        $this->assertTrue(DB::table('failed_jobs')->where('id', $job)->exists());
        $this->assertSame(0, DB::table('auth_retention_batches')->where('run_id', $run)->count());
        DB::table('failed_jobs')->where('id', $job)->delete();
    }
}
