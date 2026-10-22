<?php

namespace App\Console\Commands;

use App\Services\Auth\Client\EmailInventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuthEmailPreflight extends Command
{
    protected $signature = 'auth:email-preflight';

    protected $description = 'Read-only P3 email preflight без PII та без backfill';

    public function handle(EmailInventory $inventory): int
    {
        if (DB::getDriverName() !== 'pgsql' || DB::transactionLevel() !== 0) {
            $this->error('Потрібен окремий PostgreSQL snapshot. Жодних змін не виконано.');

            return self::FAILURE;
        }
        DB::beginTransaction();
        try {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            DB::statement("SET LOCAL statement_timeout = '30s'");
            $counts = $inventory->inspect();
            $blocked = $counts['collision_groups'] > 0 || $counts['invalid_emails'] > 0 || $counts['normalized_mismatches'] > 0;
            $this->line(json_encode(['read_only' => true, 'policy' => 'p3_ascii_lower_idna_uts46_v1',
                'application_blocked' => $blocked, 'counts' => $counts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $blocked ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Preflight недоступний. Деталі приховано; жодних змін не виконано.');

            return self::FAILURE;
        } finally {
            DB::rollBack();
        }
    }
}
