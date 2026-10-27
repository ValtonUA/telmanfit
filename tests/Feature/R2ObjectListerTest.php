<?php

namespace Tests\Feature;

use App\Services\Catalog\R2ObjectLister;
use App\Services\Catalog\R2ObjectSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class R2ObjectListerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['r2' => [
            'endpoint' => 'https://synthetic.r2.cloudflarestorage.com',
            'bucket' => 'synthetic-bucket',
            'access_key_id' => 'synthetic-access',
            'secret_access_key' => 'synthetic-secret',
        ]]);
        Http::preventStrayRequests();
    }

    #[DataProvider('missingSettings')]
    public function test_real_binding_returns_controlled_missing_configuration_without_database_access(string $key): void
    {
        config(['r2.'.$key => '']);
        Http::fake();
        DB::shouldReceive('table')->never();
        $this->assertInstanceOf(R2ObjectLister::class, app(R2ObjectSource::class));
        $this->assertSame(1, Artisan::call('assets:match-r2-images', ['--dry-run' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('Налаштуйте R2_ENDPOINT', $output);
        $this->assertStringNotContainsString('synthetic-secret', $output);
        $this->assertStringNotContainsString('synthetic-access', $output);
        Http::assertNothingSent();
    }

    public static function missingSettings(): array
    {
        return array_map(fn ($key) => [$key], ['endpoint', 'bucket', 'access_key_id', 'secret_access_key']);
    }

    public function test_signed_get_paginates_with_opaque_encoded_token_and_sorts_keys(): void
    {
        Http::fakeSequence()
            ->push($this->page('<Contents><Key>recipes/rice.webp</Key></Contents>', 'true', 'a+/= &amp;'))
            ->push($this->page('<Contents><Key>ingredients/rice.webp</Key></Contents>'));
        $this->assertSame([
            ['key' => 'ingredients/rice.webp'],
            ['key' => 'recipes/rice.webp'],
        ], app(R2ObjectSource::class)->list());
        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $this->assertSame('https://synthetic.r2.cloudflarestorage.com/synthetic-bucket?list-type=2&max-keys=1000', $requests[0][0]->url());
        $this->assertStringContainsString('continuation-token=a%2B%2F%3D%20%26&list-type=2&max-keys=1000', $requests[1][0]->url());
        foreach ($requests as [$request]) {
            $this->assertSame('GET', $request->method());
            $this->assertStringContainsString('/auto/s3/aws4_request', $request->header('Authorization')[0]);
            $this->assertSame(hash('sha256', ''), $request->header('x-amz-content-sha256')[0]);
            $this->assertSame('', $request->body());
            $this->assertFalse($request->toPsrRequest()->hasHeader('Cookie'));
        }
    }

    #[DataProvider('failures')]
    public function test_command_sanitizes_transport_http_xml_and_pagination_errors(string $failure, string $message): void
    {
        if ($failure === 'network') {
            Http::fake(fn () => throw new ConnectionException('synthetic-secret synthetic-access private URL'));
        } elseif ($failure === 'http') {
            Http::fakeSequence()->push('synthetic-secret synthetic-access', 403);
        } elseif ($failure === 'redirect') {
            Http::fakeSequence()->push('', 302, ['Location' => 'https://other.example.com']);
        } elseif ($failure === 'xml') {
            Http::fakeSequence()->push('<broken synthetic-secret synthetic-access');
        } elseif ($failure === 'root') {
            Http::fakeSequence()->push('<Error>synthetic-secret synthetic-access</Error>');
        } elseif ($failure === 'flag') {
            Http::fakeSequence()->push('<ListBucketResult/>');
        } elseif ($failure === 'missing-token') {
            Http::fakeSequence()->push($this->page('', 'true'));
        } else {
            Http::fakeSequence()->push($this->page('', 'true', 'same'))->push($this->page('', 'true', 'same'));
        }
        DB::shouldReceive('table')->never();
        $this->assertSame(1, Artisan::call('assets:match-r2-images', ['--dry-run' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString($message, $output);
        $this->assertStringNotContainsString('synthetic-secret', $output);
        $this->assertStringNotContainsString('synthetic-access', $output);
        $this->assertStringNotContainsString('BindingResolutionException', $output);
    }

    public static function failures(): array
    {
        return [
            ['network', 'Не вдалося з’єднатися з R2'],
            ['http', 'HTTP 403'],
            ['redirect', 'HTTP 302'],
            ['xml', 'некоректну XML'],
            ['root', 'некоректну XML'],
            ['flag', 'некоректний IsTruncated'],
            ['missing-token', 'не повернув token'],
            ['repeated-token', 'повторив token'],
        ];
    }

    public function test_empty_bucket_is_valid(): void
    {
        Http::fakeSequence()->push($this->page());
        $this->assertSame([], app(R2ObjectSource::class)->list());
    }

    public function test_endpoint_credentials_are_rejected_without_disclosure(): void
    {
        config(['r2.endpoint' => 'https://synthetic-access:synthetic-secret@example.com?token=private']);
        Http::fake();
        $this->assertSame(1, Artisan::call('assets:match-r2-images', ['--dry-run' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('R2_ENDPOINT має бути HTTPS', $output);
        $this->assertStringNotContainsString('synthetic-secret', $output);
        Http::assertNothingSent();
    }

    private function page(string $contents = '', string $truncated = 'false', string $token = ''): string
    {
        return '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'.$contents
            .'<IsTruncated>'.$truncated.'</IsTruncated><NextContinuationToken>'.$token.'</NextContinuationToken></ListBucketResult>';
    }
}
