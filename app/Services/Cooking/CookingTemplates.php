<?php

namespace App\Services\Cooking;

use App\Models\User;
use App\Support\Presentation\PublicImageUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Доменні primitives для наступних PR; routes/UI та activation відсутні. */
final class CookingTemplates
{
    public function review(User $actor, int $version, int $expectedRevision, array $confirmation): void
    {
        DB::transaction(function () use ($actor, $version, $expectedRevision, $confirmation): void {
            $this->draft($actor, $version, $expectedRevision);
            foreach (['suitability', 'safety', 'manual_parameters', 'no_process_water_tokens'] as $key) {
                if (($confirmation[$key] ?? null) !== true) {
                    throw ValidationException::withMessages(['confirmation' => 'Потрібне явне авторське підтвердження придатності, безпеки та ручних параметрів.']);
                }
            }
            $this->validate($version);
            DB::table('cooking_template_versions')->where('id', $version)->update([
                'reviewed_revision' => $expectedRevision, 'reviewed_by' => $actor->id, 'reviewed_at' => now(),
            ]);
        });
    }

    public function publish(User $actor, int $version, int $expectedRevision): void
    {
        DB::transaction(function () use ($actor, $version, $expectedRevision): void {
            $draft = $this->draft($actor, $version, $expectedRevision);
            if ($draft->reviewed_revision === null || (int) $draft->reviewed_revision !== $expectedRevision) {
                throw ValidationException::withMessages(['review' => 'Потрібне підтвердження поточної редакції.']);
            }
            $this->validate($version);
            DB::table('cooking_template_versions')->where('id', $version)->update(['status' => 'published', 'published_at' => now()]);
        });
    }

    private function draft(User $actor, int $version, int $expectedRevision): object
    {
        abort_unless(DB::table('users')->where('id', $actor->id)->where('role', 'admin')->exists(), 403);
        $draft = DB::table('cooking_template_versions')->where('id', $version)->lockForUpdate()->first();
        abort_unless($draft !== null, 404);
        if ($draft->status !== 'draft' || (int) $draft->revision !== $expectedRevision) {
            abort(409, 'Чернетка змінилася або вже опублікована.');
        }

        return $draft;
    }

    private function validate(int $version): void
    {
        DB::select('SELECT cooking_assert_version(?)', [$version]);
        $images = app(PublicImageUrl::class);
        foreach (DB::table('cooking_sessions')->where('version_id', $version)->get() as $session) {
            foreach (['introduction', 'equipment'] as $field) {
                if (preg_match('/[<>]|\{\{/', $session->{$field})) {
                    throw ValidationException::withMessages(['content' => 'Вступ і обладнання мають бути звичайним текстом.']);
                }
            }
            $content = json_decode($session->content, true, flags: JSON_THROW_ON_ERROR);
            foreach ($content['stages'] as $stage) {
                foreach ($stage['blocks'] as $block) {
                    if ($block['type'] === 'image' && $images->safe($block['url']) === null) {
                        throw ValidationException::withMessages(['content' => 'Недозволене зображення.']);
                    }
                }
            }
        }
    }
}
