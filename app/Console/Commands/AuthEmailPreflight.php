<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuthEmailPreflight extends Command
{
    protected $signature = 'auth:email-preflight';

    protected $description = 'Read-only оцінка email/NULL-карток без PII та без backfill';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('Потрібен PostgreSQL. Жодних змін не виконано.');

            return self::FAILURE;
        }

        // Один консистентний snapshot, серверна заборона будь-якого запису.
        DB::beginTransaction();
        try {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            DB::statement("SET LOCAL statement_timeout = '30s'");
            $counts = DB::selectOne(<<<'SQL'
                WITH candidates AS (
                  SELECT id, email, email_verified_at,
                    lower(btrim(email) COLLATE "C") AS candidate
                  FROM users
                ), collisions AS (
                  SELECT candidate, count(*) AS n FROM candidates
                  WHERE email IS NOT NULL GROUP BY candidate HAVING count(*) > 1
                )
                SELECT
                  (SELECT count(*) FROM candidates) AS users_total,
                  (SELECT count(*) FROM candidates WHERE email IS NULL) AS null_emails,
                  (SELECT count(*) FROM candidates WHERE email IS NOT NULL AND email_verified_at IS NULL) AS unverified_emails,
                  (SELECT count(*) FROM candidates WHERE email IS NOT NULL AND
                    (octet_length(email) <> char_length(email) OR octet_length(btrim(email)) NOT BETWEEN 3 AND 254
                     OR btrim(email) !~ '^[^[:space:]@]+@[^[:space:]@]+$')) AS manual_review_emails,
                  (SELECT count(*) FROM collisions) AS candidate_collision_groups,
                  (SELECT coalesce(sum(n),0) FROM collisions) AS candidate_collision_users,
                  (SELECT count(*) FROM clients WHERE user_id IS NULL) AS null_client_cards,
                  (SELECT count(*) FROM users u WHERE email IS NOT NULL AND NOT EXISTS
                    (SELECT FROM clients c WHERE c.user_id=u.id)) AS email_users_without_client
                SQL);
            $this->line(json_encode([
                'read_only' => true,
                'candidate_policy' => 'diagnostic_only_ascii_lower_trim_v0',
                'application_blocked' => true,
                'blocker' => 'Потрібні затверджена нормалізація, розгляд колізій/походження unverified і окремий backfill; ця команда не дає дозволу на запуск auth.',
                'counts' => array_map(fn ($value) => (int) $value, (array) $counts),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } finally {
            DB::rollBack();
        }

        // Навіть нуль колізій не затверджує політику нормалізації.
        return self::FAILURE;
    }
}
