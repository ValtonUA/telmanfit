<?php

namespace Tests\Auth4;

use App\Mail\EmailOtp;
use App\Models\LibraryItem;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\Client\ClientSession;
use App\Support\Library\ExternalContentUrl;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClientLibraryTest extends TestCase
{
    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__.'/../pg/auth2-guard.php';
        config(['presentation.image_hosts' => ['images.example.com']]);
        DB::beginTransaction();
        $coach = DB::table('users')->insertGetId(['name' => 'Бібліотека тренер', 'role' => 'admin']);
        $user = DB::table('users')->insertGetId(['name' => 'Бібліотека клієнт', 'email' => 'library@example.invalid',
            'email_normalized' => 'library@example.invalid', 'email_verified_at' => now()]);
        DB::table('clients')->insert(['user_id' => $user, 'coach_user_id' => $coach]);
        Mail::fake();
        $binding = bin2hex(random_bytes(32));
        $auth = app(ClientAuth::class);
        $challenge = $auth->request('login', 'library@example.invalid', null, $binding, '192.0.2.120');
        preg_match('/<strong>([0-9]{8})<\/strong>/', Mail::sent(EmailOtp::class)->last()->render(), $match);
        $proof = $auth->verify($challenge['challenge_id'], $match[1], $binding, '192.0.2.120')['proof'];
        $request = Request::create('/api/me');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->setName('telmanfit_client_session');
        $request->session()->start();
        $request->session()->put('client_binding', $binding);
        $request->session()->save();
        app()->instance('request', $request);
        app(ClientSession::class)->issue($request, $proof);
        $this->sessionId = $request->session()->getId();
        $this->withCredentials()->withCookie('telmanfit_client_session', $this->sessionId);
        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function make(string $type, string $status = 'draft', array $other = []): LibraryItem
    {
        return LibraryItem::create(array_merge([
            'type' => $type, 'title' => 'Тестовий матеріал '.$type, 'summary' => 'Короткий опис',
            'content_markdown' => in_array($type, ['article', 'instruction'], true) ? '## Безпечний текст' : null,
            'external_url' => in_array($type, ['video', 'playlist'], true) ? 'https://www.youtube.com/watch?v=test' : null,
            'status' => $status,
        ], $other));
    }

    /** Повний MVCC snapshot, включно з sessions/cache/audit. */
    private function snapshot(): array
    {
        $hashes = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
            $name = '"'.str_replace('"', '""', $table->tablename).'"';
            $hashes[$table->tablename] = DB::selectOne("SELECT md5(coalesce(string_agg(to_jsonb(t)::text || t.xmin::text, '' ORDER BY to_jsonb(t)::text), '')) AS hash FROM $name t")->hash;
        }

        return $hashes;
    }

    public function test_published_only_and_exact_details(): void
    {
        $article = $this->make('article', 'published');
        $video = $this->make('video', 'published');
        $this->make('playlist');
        $this->getJson('/api/library')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(2, 'items')->assertJsonMissing(['type' => 'playlist']);
        $this->getJson('/api/library/'.$article->id)->assertOk()->assertJsonPath('item.type', 'article')
            ->assertJsonPath('item.external_url', null);
        $this->getJson('/api/library/'.$video->id)->assertOk()
            ->assertJsonPath('item.external_url', 'https://www.youtube.com/watch?v=test')
            ->assertJsonPath('item.content_html', null);
        $this->getJson('/api/library/999999999')->assertNotFound();
    }

    public function test_hide_unpublish_and_republish_without_deletion(): void
    {
        $item = $this->make('instruction');
        $this->getJson('/api/library/'.$item->id)->assertNotFound();
        $item->update(['status' => 'published']);
        $this->getJson('/api/library/'.$item->id)->assertOk();
        $this->assertNotNull($item->fresh()->published_at);
        $item->update(['status' => 'draft']);
        $this->getJson('/api/library/'.$item->id)->assertNotFound();
        $this->assertNull($item->fresh()->published_at);
        $item->update(['status' => 'published']);
        $this->getJson('/api/library/'.$item->id)->assertOk();
    }

    public function test_markdown_is_sanitized_and_external_links_fail_closed(): void
    {
        $markdown = "## Розділ\n\n<script>alert(1)</script>\n\n![добре](https://images.example.com/a.jpg)\n"
            ."![погано](https://example.net/a.jpg)\n\n[кнопка](javascript:alert(1))\n\n[YouTube](https://www.youtube.com/watch?v=ok)";
        $article = $this->make('article', 'published', ['content_markdown' => $markdown]);
        $body = $this->getJson('/api/library/'.$article->id)->assertOk()->json('item.content_html');
        $this->assertStringContainsString('<h2>', $body);
        $this->assertStringContainsString('images.example.com/a.jpg', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertStringNotContainsString('example.net', $body);
        $this->assertStringContainsString('noopener noreferrer', $body);
        foreach (['http://example.com', 'https://127.0.0.1/x', 'https://user:pw@example.com/x', 'javascript:alert(1)'] as $url) {
            $this->assertNull(ExternalContentUrl::safe($url));
        }
    }

    public function test_publishing_requires_content_and_sql_check(): void
    {
        foreach (['video', 'playlist'] as $type) {
            $item = $this->make($type);
            try {
                $item->update(['external_url' => null, 'status' => 'published']);
                $this->fail('Порожній матеріал не можна публікувати.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('external_url', $e->errors());
            }
        }
        try {
            $this->make('article', 'published', ['content_markdown' => null, 'external_url' => null]);
            $this->fail('Потрібен текст або URL.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('content_markdown', $e->errors());
        }
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            DB::table('library_items')->insert(['type' => 'video', 'title' => 'Недійсний', 'status' => 'published',
                'published_at' => now(), 'external_url' => null]);
            $this->fail('SQL CHECK має відхиляти неповну публікацію.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        } finally {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
        }
    }

    public function test_readonly_get_head_and_revoked_session(): void
    {
        $item = $this->make('article', 'published');
        config(['session.lottery' => [100, 100]]);
        $before = $this->snapshot();
        foreach (['/api/library', '/api/library/'.$item->id] as $url) {
            $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $this->head($url)->assertOk();
        }
        $this->assertSame($before, $this->snapshot());
        DB::table('auth_session_states')->where('session_id', $this->sessionId)->update(['revoked_at' => DB::raw('clock_timestamp()')]);
        $this->getJson('/api/library')->assertUnauthorized();
        $this->getJson('/api/library/'.$item->id)->assertUnauthorized();
    }
}
