<?php

namespace App\Services\Images;

use App\Models\AiSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ImageDownloadService
{
    private const USER_AGENT = 'Alta-Trade Product Image Picker/1.0 (+https://alta-trade.com.ua)';

    /**
     * @return array{url: string, body: string, content_type: ?string, http_status: int, size: int, width: ?int, height: ?int, mime_type: ?string}
     */
    public function download(string $url): array
    {
        $response = $this->fetch(
            $url,
            $this->maxDownloadBytes(),
            (int) config('image_search.image_timeout', 5),
            (int) config('image_search.image_connect_timeout', 2),
        );
        $contentType = $this->contentType($response);
        $body = $response->body();
        $size = strlen($body);
        $maxBytes = $this->maxDownloadBytes();

        if ($size > $maxBytes) {
            throw new RuntimeException('Файл перевищує ліміт завантаження.');
        }

        $actualMime = $this->actualMimeType($body);
        $dimensions = @getimagesizefromstring($body) ?: null;

        return [
            'url' => $url,
            'body' => $body,
            'content_type' => $contentType,
            'http_status' => $response->status(),
            'size' => $size,
            'width' => is_array($dimensions) ? (int) ($dimensions[0] ?? 0) : null,
            'height' => is_array($dimensions) ? (int) ($dimensions[1] ?? 0) : null,
            'mime_type' => $actualMime ?: $contentType,
        ];
    }

    /**
     * @return array{url: string, body: string, content_type: ?string, size: int}
     */
    public function downloadHtml(string $url): array
    {
        $response = $this->fetch(
            $url,
            2 * 1024 * 1024,
            (int) config('image_search.source_page_timeout', 4),
            (int) config('image_search.source_page_connect_timeout', 2),
        );
        $contentType = $this->contentType($response);

        if ($contentType !== 'text/html' && $contentType !== 'application/xhtml+xml') {
            throw new RuntimeException('URL сторінки має повернути HTML, отримано: '.($contentType ?: 'unknown').'.');
        }

        $body = $response->body();

        if (strlen($body) > 2 * 1024 * 1024) {
            throw new RuntimeException('HTML сторінка перевищує ліміт 2 MB.');
        }

        return [
            'url' => $url,
            'body' => $body,
            'content_type' => $contentType,
            'size' => strlen($body),
        ];
    }

    public function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Дозволені тільки http/https URL.');
        }

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new RuntimeException('Localhost/internal URL заборонено.');
        }

        if (! str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('Internal hostnames без публічного домену заборонені.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false && ! $this->isPublicIp($host)) {
            throw new RuntimeException('Private/reserved IP URL заборонено.');
        }

        foreach (['.localhost', '.local', '.internal', '.lan', '.home', '.intranet'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new RuntimeException('Internal hostnames заборонені.');
            }
        }

        $this->assertResolvedHostIsPublic($host);
    }

    public function maxDownloadBytes(): int
    {
        $megabytes = AiSetting::getActive()->image_search_max_download_size_mb ?: 5;

        return max(1, (int) $megabytes) * 1024 * 1024;
    }

    private function fetch(string $url, int $maxBytes, int $timeout, int $connectTimeout): Response
    {
        $currentUrl = $url;
        $maxRedirects = max(0, (int) config('image_search.max_redirects', 3));

        for ($redirects = 0; $redirects <= $maxRedirects; $redirects++) {
            $this->assertSafeUrl($currentUrl);

            try {
                $response = Http::connectTimeout($connectTimeout)
                    ->timeout($timeout)
                    ->withUserAgent(self::USER_AGENT)
                    ->withOptions(['allow_redirects' => false])
                    ->get($currentUrl);
            } catch (ConnectionException $exception) {
                throw new RuntimeException('connection_timeout: URL недоступний або перевищено timeout.');
            } catch (RequestException $exception) {
                throw new RuntimeException('connection_failed: URL недоступний або запит не виконано.');
            } catch (Throwable $exception) {
                throw new RuntimeException('connection_failed: URL недоступний або запит не виконано.');
            }

            if ($response->status() >= 300 && $response->status() < 400 && filled($response->header('Location'))) {
                $currentUrl = $this->resolveRedirectUrl($currentUrl, (string) $response->header('Location'));

                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException('URL фото недоступний або повернув HTTP '.$response->status().'.');
            }

            $contentLength = (int) ($response->header('Content-Length') ?: 0);

            if ($contentLength > $maxBytes) {
                throw new RuntimeException('Файл перевищує ліміт завантаження.');
            }

            return $response;
        }

        throw new RuntimeException('Забагато redirect під час завантаження фото.');
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (str_starts_with($location, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return $scheme.':'.$location;
        }

        if (preg_match('/^https?:\/\//i', $location) === 1) {
            return $location;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';

        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$host.$location;
        }

        $path = $base['path'] ?? '/';
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $scheme.'://'.$host.($directory === '' ? '' : $directory).'/'.$location;
    }

    private function contentType(Response $response): ?string
    {
        $contentType = $response->header('Content-Type');

        if (! is_string($contentType) || $contentType === '') {
            return null;
        }

        return strtolower(trim(explode(';', $contentType)[0]));
    }

    private function actualMimeType(string $body): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($body);

        return is_string($mime) && $mime !== '' ? strtolower($mime) : null;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function assertResolvedHostIsPublic(string $host): void
    {
        $this->assertNotBlockedDomain($host);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (! is_array($records) || $records === []) {
            return;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip) && ! $this->isPublicIp($ip)) {
                throw new RuntimeException('Host resolves to private/reserved IP, URL заборонено.');
            }
        }
    }

    private function assertNotBlockedDomain(string $host): void
    {
        $host = strtolower(trim($host, '.'));

        foreach ((array) config('image_search.blocked_domains', []) as $domain) {
            $domain = strtolower(trim((string) $domain, '.'));

            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                throw new RuntimeException('blocked_domain: Домен заблокований політикою пошуку фото.');
            }
        }

        $parts = explode('.', $host);
        $tld = end($parts);

        if (is_string($tld) && in_array($tld, array_map('strtolower', (array) config('image_search.blocked_tlds', [])), true)) {
            throw new RuntimeException('blocked_domain: Домен заблокований політикою пошуку фото.');
        }
    }
}
