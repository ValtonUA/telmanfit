<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\Client\ClientSession;
use App\Support\Library\ExternalContentUrl;
use App\Support\Library\LibraryMarkdown;
use App\Support\Presentation\PublicImageUrl;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ClientLibraryController
{
    public function index(Request $request, ClientSession $sessions): JsonResponse
    {
        return DB::transaction(function () use ($request, $sessions): JsonResponse {
            $user = $sessions->user($request);
            abort_unless(DB::table('clients')->where('user_id', $user->id)->exists(), 401);
            $items = $this->published()->orderByDesc('published_at')->orderByDesc('id')
                ->get(['id', 'type', 'title', 'summary', 'cover_image_url', 'published_at'])
                ->map(fn (object $row): array => $this->card($row))->all();

            return response()->json(['items' => $items])->header('Cache-Control', 'no-store, private');
        });
    }

    public function show(Request $request, string $item, ClientSession $sessions): JsonResponse
    {
        return DB::transaction(function () use ($request, $item, $sessions): JsonResponse {
            $user = $sessions->user($request);
            abort_unless(DB::table('clients')->where('user_id', $user->id)->exists(), 401);
            if (! preg_match('/^[1-9][0-9]{0,17}$/D', $item)) {
                abort(404);
            }
            $row = $this->published()->where('id', $item)->first();
            abort_unless($row, 404);

            $item = $this->card($row);
            $item['external_url'] = ExternalContentUrl::safe($row->external_url);
            $item['content_html'] = in_array($row->type, ['article', 'instruction'], true)
                ? LibraryMarkdown::render($row->content_markdown) : null;

            // Некоректні історичні URL не мають ставати активними лінками.
            if (in_array($row->type, ['video', 'playlist'], true) && $item['external_url'] === null) {
                abort(404);
            }
            if ($item['external_url'] === null && $item['content_html'] === null) {
                abort(404);
            }

            return response()->json(['item' => $item])->header('Cache-Control', 'no-store, private');
        });
    }

    private function published(): Builder
    {
        return DB::table('library_items')->where('status', 'published')
            ->whereNotNull('published_at')->where('published_at', '<=', DB::raw('CURRENT_TIMESTAMP'));
    }

    private function card(object $row): array
    {
        return [
            'id' => (string) $row->id, 'type' => $row->type,
            'title' => $row->title, 'summary' => $row->summary,
            'cover_image_url' => PublicImageUrl::safe($row->cover_image_url),
            'published_at' => $row->published_at,
        ];
    }
}
