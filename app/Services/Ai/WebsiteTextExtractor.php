<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fetches and converts a member-supplied public website into plain text.
 *
 * This is intentionally conservative: only http/https, only public IPs, no
 * credentials in the URL and a small redirect/body budget. It prevents the AI
 * feature from becoming an SSRF primitive.
 */
class WebsiteTextExtractor
{
    public function fetch(string $url): string
    {
        $current = $this->assertSafeUrl($url);
        $timeout = max(5, (int) config('roicard.ai.timeout_seconds', 20));

        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                $response = Http::withOptions(['allow_redirects' => false])
                    ->withHeaders([
                        'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9',
                        'User-Agent' => 'Roicard/1.0 (+profile drafting)',
                    ])
                    ->timeout($timeout)
                    ->get($current);
            } catch (ConnectionException $exception) {
                throw new AiInputException('website_unreachable', 'That website could not be reached. Paste the text instead.');
            }

            if ($response->status() >= 300 && $response->status() < 400) {
                $location = trim((string) $response->header('Location'));

                if ($location === '') {
                    throw new AiInputException('website_unreachable', 'That website redirected without a destination. Paste the text instead.');
                }

                $current = $this->assertSafeUrl($this->resolveUrl($current, $location));
                continue;
            }

            if (! $response->successful()) {
                throw new AiInputException('website_unreachable', 'That website returned an error. Paste the text instead.');
            }

            $contentType = strtolower((string) $response->header('Content-Type'));

            if ($contentType !== '' && ! str_contains($contentType, 'text/') && ! str_contains($contentType, 'html')) {
                throw new AiInputException('website_not_text', 'That link is not a readable web page. Paste the text instead.');
            }

            $maxBytes = max(1024, (int) config('roicard.ai.max_website_bytes', 400000));
            $body = substr($response->body(), 0, $maxBytes);
            $text = $this->htmlToText($body);

            if (mb_strlen($text) < 40) {
                throw new AiInputException('website_empty', 'That website did not contain enough readable text. Paste the text instead.');
            }

            return mb_substr($text, 0, max(500, (int) config('roicard.ai.max_website_chars', 12000)));
        }

        throw new AiInputException('website_redirects', 'That website redirected too many times. Paste the text instead.');
    }

    protected function assertSafeUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new AiInputException('invalid_url', 'Enter a complete website URL, for example https://example.com.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new AiInputException('invalid_url', 'Only http and https website links are supported.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new AiInputException('invalid_url', 'Website links with embedded credentials are not supported.');
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && ! in_array((int) $port, [80, 443], true)) {
            throw new AiInputException('invalid_url', 'Only standard website ports are supported.');
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new AiInputException('invalid_url', 'That website address is not allowed.');
        }

        $ips = $this->resolveHost($host);

        if ($ips === []) {
            throw new AiInputException('website_unreachable', 'That website address could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new AiInputException('invalid_url', 'That website address is not allowed.');
            }
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . ($path === '' ? '/' : $path) . $query;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);

            if (is_array($records)) {
                foreach ($records as $record) {
                    $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                    if (is_string($ip) && $ip !== '') {
                        $ips[] = $ip;
                    }
                }
            }
        }

        if ($ips === []) {
            $ips = gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }

    protected function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $lower = strtolower($ip);

        return ! in_array($lower, ['::1', '0.0.0.0', '::'], true)
            && ! str_starts_with($lower, 'fe80:')
            && ! str_starts_with($lower, 'fc')
            && ! str_starts_with($lower, 'fd');
    }

    protected function resolveUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);

        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }

        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin . $dir . '/' . $location;
    }

    protected function htmlToText(string $html): string
    {
        $html = (string) preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
        $html = (string) preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html);
        $html = (string) preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $html);
        $html = (string) preg_replace('/<!--.*?-->/s', ' ', $html);
        $html = (string) preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = (string) preg_replace('/<\/(p|div|section|article|li|h[1-6])>/i', "\n", $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/\n\s*\n+/u', "\n", $text);

        return trim($text);
    }
}
