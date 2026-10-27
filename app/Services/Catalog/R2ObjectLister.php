<?php

namespace App\Services\Catalog;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

final class R2ObjectLister implements R2ObjectSource
{
    /** @return list<array{key: string}> */
    public function list(): array
    {
        $endpoint = (string) config('r2.endpoint');
        $bucket = (string) config('r2.bucket');
        $accessKey = (string) config('r2.access_key_id');
        $secretKey = (string) config('r2.secret_access_key');
        $parts = parse_url($endpoint);

        if (trim($bucket) === '' || trim($accessKey) === '' || trim($secretKey) === '' || $endpoint === '') {
            throw new RuntimeException('Налаштуйте R2_ENDPOINT, R2_BUCKET, R2_ACCESS_KEY_ID та R2_SECRET_ACCESS_KEY.');
        }
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('R2_ENDPOINT має бути HTTPS URL без credentials, query або fragment.');
        }

        $basePath = trim($parts['path'] ?? '', '/');
        $path = '/'.($basePath === '' ? '' : $basePath.'/').rawurlencode($bucket);
        $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $query = ['list-type' => '2', 'max-keys' => '1000'];
        $objects = [];
        $seenTokens = [];

        do {
            $queryString = $this->query($query);
            $date = gmdate('Ymd\THis\Z');
            $dateStamp = substr($date, 0, 8);
            $payloadHash = hash('sha256', '');
            $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$date}\n";
            $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
            $canonicalRequest = "GET\n{$path}\n{$queryString}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
            $scope = "{$dateStamp}/auto/s3/aws4_request";
            $stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n".hash('sha256', $canonicalRequest);
            $signingKey = $this->signingKey($secretKey, $dateStamp);
            $signature = hash_hmac('sha256', $stringToSign, $signingKey);
            $authorization = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
            $url = $parts['scheme'].'://'.$host.$path.'?'.$queryString;

            try {
                $response = Http::withHeaders([
                    'Authorization' => $authorization,
                    'x-amz-content-sha256' => $payloadHash,
                    'x-amz-date' => $date,
                ])->connectTimeout(10)->timeout(30)->withoutRedirecting()->get($url);
            } catch (ConnectionException) {
                // Не передавати transport exception: він може містити URL або headers.
                throw new RuntimeException('Не вдалося з’єднатися з R2. Перевірте мережу, DNS і R2_ENDPOINT.');
            }

            if (! $response->successful()) {
                throw new RuntimeException('S3 ListObjectsV2 завершився HTTP '.$response->status().'.');
            }

            $previousErrors = libxml_use_internal_errors(true);
            try {
                $xml = simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousErrors);
            }
            if (! $xml instanceof SimpleXMLElement || $xml->getName() !== 'ListBucketResult') {
                throw new RuntimeException('R2 повернув некоректну XML-відповідь.');
            }

            $xmlNamespace = $xml->getNamespaces(true)[''] ?? '';
            $xml = $xmlNamespace !== '' ? $xml->children($xmlNamespace) : $xml;
            foreach ($xml->Contents ?? [] as $content) {
                $objects[] = ['key' => (string) $content->Key];
            }

            $truncatedValue = (string) ($xml->IsTruncated ?? '');
            if (! in_array($truncatedValue, ['true', 'false'], true)) {
                throw new RuntimeException('R2 повернув некоректний IsTruncated.');
            }
            $truncated = $truncatedValue === 'true';
            $token = (string) ($xml->NextContinuationToken ?? '');
            if ($truncated && $token === '') {
                throw new RuntimeException('R2 не повернув token для наступної сторінки.');
            }
            if ($truncated) {
                if (isset($seenTokens[$token])) {
                    throw new RuntimeException('R2 повторив token пагінації; читання зупинено.');
                }
                $seenTokens[$token] = true;
                $query['continuation-token'] = $token;
            }
        } while ($truncated);

        usort($objects, fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return $objects;
    }

    private function query(array $parameters): string
    {
        ksort($parameters, SORT_STRING);

        return implode('&', array_map(fn (string $key, string $value): string => rawurlencode($key).'='.rawurlencode($value), array_keys($parameters), array_values($parameters)));
    }

    private function signingKey(string $secret, string $date): string
    {
        $dateKey = hash_hmac('sha256', $date, 'AWS4'.$secret, true);
        $regionKey = hash_hmac('sha256', 'auto', $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }
}
