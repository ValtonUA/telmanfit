<?php

namespace App\Services\Cooking;

use App\Models\User;
use App\Support\Presentation\PublicImageUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Адміністративні команди: одна транзакція, root-first CAS, без повтору старого intent. */
final class CookingEditor
{
    public function authorize(User $actor): void
    {
        abort_unless(DB::table('users')->where('id', $actor->id)->where('role', 'admin')->sharedLock()->exists(), 403);
    }

    public function create(User $actor, array $data, ?int $previous = null): int
    {
        return DB::transaction(function () use ($actor, $data, $previous) {
            $this->authorize($actor);
            Validator::make($data, ['title' => 'required|string|max:500', 'source_plan_version_id' => 'required|integer|min:1', 'source_week' => 'required|integer|min:1|max:4681', 'mode' => 'required|integer|in:1,2,7'])->validate();
            DB::selectOne('SELECT cooking_menu_pattern(?,?)', [$data['source_plan_version_id'], $data['source_week']]);
            $number = 1;
            if ($previous !== null) {
                $old = DB::table('cooking_template_versions')->where('id', $previous)->first();
                abort_unless($old && $old->status === 'published', 409);
                $template = $old->template_id;
                DB::table('cooking_templates')->where('id', $template)->lockForUpdate()->first();
                $number = $old->version_number + 1;
            } else {
                $template = DB::table('cooking_templates')->insertGetId(['title' => $data['title'], 'created_by' => $actor->id]);
            }

            return DB::table('cooking_template_versions')->insertGetId(['template_id' => $template, 'version_number' => $number, 'previous_version_id' => $previous,
                'source_plan_version_id' => $data['source_plan_version_id'], 'source_week' => $data['source_week'], 'mode' => $data['mode']]);
        });
    }

    public function edit(User $actor, int $version, int $revision, callable $command): mixed
    {
        return DB::transaction(function () use ($actor, $version, $revision, $command) {
            $this->authorize($actor);
            DB::select('SELECT cooking_assert_revision(?,?)', [$version, $revision]);

            return $command();
        });
    }

    public function session(User $actor, int $version, int $revision, array $data, ?int $id = null): int
    {
        return $this->edit($actor, $version, $revision, function () use ($version, $data, $id) {
            Validator::make($data, ['day' => 'required|integer|between:1,7', 'position' => 'required|integer|min:1|max:32767',
                'estimated_seconds' => 'required|integer|min:1|max:2147483647', 'introduction' => 'nullable|string', 'equipment' => 'nullable|string', 'content' => 'required|array'])->validate();
            foreach (['introduction', 'equipment'] as $field) {
                if (preg_match('/[<>]|\{\{/', $data[$field] ?? '')) {
                    throw ValidationException::withMessages(['content' => 'Вступ та обладнання — звичайний текст.']);
                }
            }
            if ($id !== null) {
                abort_unless(DB::table('cooking_sessions')->where('version_id', $version)->where('id', $id)->exists(), 404);
            }
            $content = json_encode($data['content'], JSON_THROW_ON_ERROR);
            if (! DB::selectOne('SELECT cooking_content_valid(?::jsonb) AS valid', [$content])->valid) {
                throw ValidationException::withMessages(['content' => 'Перевірте структуру етапів і блоків.']);
            }
            foreach ($data['content']['stages'] as $stage) {
                foreach ($stage['blocks'] as $block) {
                    if ($block['type'] === 'image' && app(PublicImageUrl::class)->safe($block['url']) === null) {
                        throw ValidationException::withMessages(['content' => 'Недозволене зображення.']);
                    }
                    if ($block['type'] === 'action') {
                        foreach ($block['parts'] as $part) {
                            if (is_array($part)) {
                                abort_unless($id !== null, 422, 'Спочатку збережіть сесію та додайте приготування.');
                                $this->token($id, $part);
                            }
                        }
                    }
                }
            }
            $row = ['day' => $data['day'], 'position' => $data['position'], 'estimated_seconds' => $data['estimated_seconds'],
                'introduction' => $data['introduction'] ?? '', 'equipment' => $data['equipment'] ?? '', 'content' => $content];
            if ($id !== null) {
                abort_unless(DB::table('cooking_sessions')->where('version_id', $version)->where('id', $id)->exists(), 404);
                DB::table('cooking_sessions')->where('id', $id)->update($row);

                return $id;
            }

            return DB::table('cooking_sessions')->insertGetId($row + ['version_id' => $version]);
        });
    }

    public function output(User $actor, int $version, int $revision, int $session, string $selector): int
    {
        return $this->edit($actor, $version, $revision, function () use ($version, $session, $selector) {
            abort_unless(DB::table('cooking_sessions')->where('version_id', $version)->where('id', $session)->exists(), 404);
            $choice = $this->selectors($version)[$selector] ?? null;
            abort_unless($choice !== null, 422);

            return DB::table('cooking_outputs')->insertGetId(['version_id' => $version, 'session_id' => $session,
                'recipe_version_id' => $choice['recipe_version_id'], 'format_id' => $choice['format_id'], 'portion_divisor' => $choice['divisor']]);
        });
    }

    public function selectors(int $version): array
    {
        $pattern = json_decode(DB::table('cooking_template_versions')->where('id', $version)->value('menu_pattern'), true);
        $choices = [];
        foreach ($pattern['items'] as $slot) {
            if ($slot['recipe_version_id'] !== null) {
                $key = $slot['recipe_version_id'].':'.($slot['format_id'] ?? 0).':'.$slot['divisor'];
                $choices[$key] = $slot;
            }
        }

        return $choices;
    }

    public function allocate(User $actor, int $version, int $revision, int $day, int $meal, int $item, ?int $output): void
    {
        $this->edit($actor, $version, $revision, function () use ($version, $day, $meal, $item, $output) {
            $pattern = json_decode(DB::table('cooking_template_versions')->where('id', $version)->value('menu_pattern'), true);
            $slot = collect($pattern['items'])->first(fn ($s) => $s['day'] === $day && $s['meal_position'] === $meal && $s['item_position'] === $item && $s['recipe_version_id'] !== null);
            abort_unless($slot !== null, 422);
            $query = DB::table('cooking_allocations')->where('version_id', $version)->where('day', $day)->where('meal_position', $meal)->where('item_position', $item);
            if ($output !== null) {
                $o = DB::table('cooking_outputs as o')->join('cooking_sessions as s', 's.id', '=', 'o.session_id')->where('o.version_id', $version)->where('o.id', $output)->select('o.*', 's.day')->first();
                abort_unless($o && $o->day <= $day && (int) $o->recipe_version_id === $slot['recipe_version_id'] && $o->format_id === $slot['format_id'] && bccomp($o->portion_divisor, (string) $slot['divisor'], 2) === 0, 422, 'Приготування не відповідає страві або дню.');
                $allocated = DB::table('cooking_allocations')->where('output_id', $output)->whereRaw('NOT (day=? AND meal_position=? AND item_position=?)', [$day, $meal, $item])->sum('portions');
                abort_unless(bccomp(bcadd((string) $allocated, (string) $slot['quantity'], 2), $o->portion_divisor, 2) <= 0, 422, 'Недостатньо приготовлених порцій.');
            }
            $query->delete();
            if ($output !== null) {
                DB::table('cooking_allocations')->insert(['version_id' => $version, 'day' => $day, 'meal_position' => $meal, 'item_position' => $item, 'output_id' => $output, 'portions' => $slot['quantity']]);
            }
        });
    }

    public function remove(User $actor, int $version, int $revision, string $kind, int $id): void
    {
        $this->edit($actor, $version, $revision, function () use ($version, $kind, $id) {
            abort_unless(in_array($kind, ['sessions', 'outputs'], true), 422);
            abort_unless(DB::table('cooking_'.$kind)->where('version_id', $version)->where('id', $id)->exists(), 404);
            if ($kind === 'outputs') {
                foreach (DB::table('cooking_sessions')->where('version_id', $version)->get() as $session) {
                    foreach (json_decode($session->content, true)['stages'] as $stage) {
                        foreach ($stage['blocks'] as $block) {
                            foreach ($block['type'] === 'action' ? $block['parts'] : [] as $part) {
                                if (is_array($part) && collect($part['sources'])->contains(fn ($source) => $source['output'] === $id)) {
                                    abort(422, 'Спочатку приберіть посилання на це приготування з дій.');
                                }
                            }
                        }
                    }
                }
            }
            // FK не дозволяють видалити використане приготування або непорожню сесію.
            DB::table('cooking_'.$kind)->where('id', $id)->delete();
        });
    }

    public function token(int $session, array $token): array
    {
        $result = json_decode(DB::selectOne('SELECT cooking_token(?,?::jsonb) AS result', [$session, json_encode($token, JSON_THROW_ON_ERROR)])->result, true);
        if ($result['unit_label'] === null) {
            throw ValidationException::withMessages(['content' => 'Frozen одиниця недоступна. Оберіть грами або авторський текст.']);
        }

        return $result;
    }
}
