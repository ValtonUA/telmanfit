<?php

namespace App\Services\Auth\Client;

use Illuminate\Support\Facades\DB;

final class EmailInventory
{
    public function inspect(): array
    {
        $counts = ['users_total' => 0, 'null_emails' => 0, 'unverified_emails' => 0, 'invalid_emails' => 0,
            'candidate_collision_groups' => 0, 'candidate_collision_users' => 0, 'manual_review_emails' => 0, 'collision_groups' => 0, 'normalized_mismatches' => 0, 'backfill_candidates' => 0];
        $keys = [];
        foreach (DB::table('users')->orderBy('id')->get(['email', 'email_normalized', 'email_verified_at']) as $row) {
            $counts['users_total']++;
            if ($row->email === null) {
                $counts['null_emails']++;

                continue;
            }
            $counts['unverified_emails'] += $row->email_verified_at === null ? 1 : 0;
            try {
                $key = app(EmailNormalizer::class)->normalize($row->email);
            } catch (AuthDenied) {
                $counts['invalid_emails']++;

                continue;
            }
            $keys[$key] = ($keys[$key] ?? 0) + 1;
            $counts['normalized_mismatches'] += $row->email_normalized !== null && $row->email_normalized !== $key ? 1 : 0;
            $counts['backfill_candidates'] += $row->email_normalized === null ? 1 : 0;
        }
        $collisions = array_filter($keys, fn ($n) => $n > 1);
        $counts['collision_groups'] = $counts['candidate_collision_groups'] = count($collisions);
        $counts['candidate_collision_users'] = array_sum($collisions);
        $counts['manual_review_emails'] = $counts['invalid_emails'];
        $counts['null_client_cards'] = DB::table('clients')->whereNull('user_id')->count();

        return $counts;
    }

    /** Лише synthetic runner, явний exclusive lock і повторний preflight у транзакції. */
    public function syntheticBackfill(): void
    {
        require base_path('tests/pg/auth2-guard.php');
        if (! app()->environment('testing')) {
            throw new AuthDenied('synthetic_only', 403);
        }
        DB::transaction(function () {
            DB::statement('LOCK TABLE users IN ACCESS EXCLUSIVE MODE');
            $counts = $this->inspect();
            if ($counts['collision_groups'] || $counts['invalid_emails'] || $counts['normalized_mismatches']) {
                throw new AuthDenied('backfill_blocked', 409);
            }
            foreach (DB::table('users')->whereNotNull('email')->whereNull('email_normalized')->get(['id', 'email']) as $row) {
                DB::table('users')->where('id', $row->id)->update(['email_normalized' => app(EmailNormalizer::class)->normalize($row->email)]);
            }
        });
    }
}
