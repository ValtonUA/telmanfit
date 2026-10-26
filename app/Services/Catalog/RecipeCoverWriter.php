<?php

namespace App\Services\Catalog;

use App\Models\User;
use App\Support\Presentation\PublicImageUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Лише mutable logical cover; recipe_versions і snapshots не записуються. */
final class RecipeCoverWriter
{
    public function token(User $actor, int $recipeId): string
    {
        abort_unless(CatalogWriter::allowed($actor), 403);
        $row = DB::table('recipes')->where('id', $recipeId)->selectRaw('*, xmin::text as revision')->first();
        abort_unless($row, 404);

        return hash_hmac('sha256', json_encode([$actor->id, $row], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function save(User $actor, int $recipeId, ?string $url, string $expected): void
    {
        abort_unless(CatalogWriter::allowed($actor), 403);
        DB::transaction(function () use ($actor, $recipeId, $url, $expected): void {
            DB::table('auth_security_runtime')->where('id', 1)->sharedLock()->first();
            User::whereKey($actor->id)->sharedLock()->firstOrFail();
            abort_unless(CatalogWriter::allowed($actor), 403);
            abort_unless(DB::table('recipes')->where('id', $recipeId)->lockForUpdate()->first(), 404);
            if (! hash_equals($this->token($actor, $recipeId), $expected)) {
                throw ValidationException::withMessages(['image_url' => 'Обкладинку або рецепт змінено. Відкрийте форму повторно.']);
            }
            $data = Validator::make(['image_url' => $url === '' ? null : $url], ['image_url' => ['nullable', new PublicImageUrl]])->validate();
            DB::table('recipes')->where('id', $recipeId)->update(['image_url' => $data['image_url']]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            abort_unless(CatalogWriter::allowed($actor), 403);
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        });
    }
}
