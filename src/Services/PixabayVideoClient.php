<?php

namespace App\Services;

use RuntimeException;

/** Bounded, user-triggered stock searches; response caching follows Pixabay's 24-hour requirement. */
class PixabayVideoClient
{
    private string $apiKey;
    private string $cacheRoot;
    private const MAX_BYTES = 83886080;
    private const LOCAL_REQUEST_LIMIT = 30;
    private ?array $lastResponse = null;
    private int $retryUntil = 0;
    private bool $cacheHit = false;

    public function __construct()
    {
        $this->apiKey = trim((string)($_ENV['PIXABAY_API_KEY'] ?? ''));
        $this->cacheRoot = ShortsEnvironment::configuration()['pixabay_cache_root'];
        if ($this->apiKey === '') {
            throw new RuntimeException('서버 .env에 PIXABAY_API_KEY를 설정하세요. Pixabay API 문서에 로그인하면 키를 확인할 수 있습니다.');
        }
    }

    public function search(string $query): array
    {
        $params = $this->queryParams($query);
        $this->ensureCacheRoot();
        $path = $this->cachePath($params);
        $this->cacheHit = false;
        // Also serialize identical searches from separate PHP workers.
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) { fclose($lock); }
            throw new RuntimeException('Pixabay 캐시를 잠글 수 없습니다. 캐시 폴더 권한을 확인하세요.');
        }
        try {
            $cached = $this->readCache($path);
            if ($cached !== null) {
                $this->cacheHit = true;
                return $cached;
            }
            $data = $this->withRateState(function (array &$state) use ($params): array {
                $this->assertRateState($state);
                $now = time();
                if ($now - (int)($state['window_start'] ?? 0) >= 60) {
                    $state['window_start'] = $now;
                    $state['requests'] = 0;
                }
                // A conservative local budget shared by different queries and PHP workers.
                if ((int)($state['requests'] ?? 0) >= self::LOCAL_REQUEST_LIMIT) {
                    $state['blocked_until'] = (int)$state['window_start'] + 60;
                    $state['reason'] = 'local_budget';
                    $this->assertRateState($state);
                }
                $state['requests'] = (int)($state['requests'] ?? 0) + 1;
                $this->lastResponse = null;
                $this->retryUntil = 0;
                try {
                    return $this->fetchSearch($params + ['key' => $this->apiKey]);
                } finally {
                    if ($this->lastResponse !== null) { $state['last_response'] = $this->lastResponse; }
                    if ($this->retryUntil > time()) {
                        $state['blocked_until'] = $this->retryUntil;
                        $state['reason'] = $this->lastResponse['kind'] ?? 'provider';
                    }
                    if ($state['requests'] >= self::LOCAL_REQUEST_LIMIT
                        && (int)$state['window_start'] + 60 > (int)($state['blocked_until'] ?? 0)) {
                        $state['blocked_until'] = (int)$state['window_start'] + 60;
                        $state['reason'] = 'local_budget';
                    }
                }
            });
            if (!isset($data['hits']) || !is_array($data['hits'])) {
                throw new RuntimeException('Pixabay 검색 응답을 해석하지 못했습니다.');
            }
            if (file_put_contents($path, json_encode(['hits' => $data['hits']], JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('Pixabay 검색 결과를 캐시에 저장하지 못했습니다.');
            }
            return $data['hits'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function fetchSearch(array $params): array
    {
        $response = $this->sendSearchRequest($params);
        $status = $response['status'];
        $headers = $response['headers'];
        $body = $response['body'];
        $html = stripos($headers['content-type'] ?? '', 'text/html') !== false
            || preg_match('/^\s*<(?:!doctype|html)/i', $body) === 1;
        $apiLimit = !$html && preg_match('/(?:api\s+)?rate\s+limit\s+exceeded/i', $body) === 1;
        $wait = $this->retrySeconds($headers);
        $this->lastResponse = ['checked_at' => gmdate('c'), 'http_status' => $status,
            'kind' => $html ? 'html_response' : ($apiLimit ? 'api_rate_limit' : ($status === 200 ? 'json_response' : 'other_response')),
            'limit' => $this->numericHeader($headers, 'x-ratelimit-limit'),
            'remaining' => $this->numericHeader($headers, 'x-ratelimit-remaining'),
            'reset_seconds' => $this->numericHeader($headers, 'x-ratelimit-reset'),
            'retry_after_seconds' => isset($headers['retry-after']) ? $wait : null];
        if ($status === 429 || ($status === 200 && $this->lastResponse['remaining'] === 0)) {
            $this->retryUntil = time() + $wait;
        }
        // Do not expose request URLs, raw provider bodies, API keys or cookies in diagnostics/history.
        if ($status === 429) {
            $reason = $apiLimit ? 'Pixabay API 요청 한도에 도달했습니다.'
                : ($html ? 'Pixabay가 HTML 형식의 접속 제한 응답(HTTP 429)을 반환했습니다. API 사용량 한도인지는 확인되지 않았습니다.'
                    : 'Pixabay가 HTTP 429를 반환했습니다. 응답만으로 API 사용량 제한의 원인을 확인할 수 없습니다.');
            throw new RuntimeException($reason . ' 약 ' . $wait . '초 후 다시 시도하세요. 계속되면 php scripts/check_pixabay.php 로 응답을 확인하세요.');
        }
        if ($html) {
            throw new RuntimeException('Pixabay가 API JSON 대신 HTML 응답(HTTP ' . $status . ')을 반환했습니다. 운영 서버의 접속 제한 여부를 php scripts/check_pixabay.php 로 확인하세요.');
        }
        if ($status === 400 || $status === 401 || $status === 403) {
            throw new RuntimeException('Pixabay 인증 또는 검색 요청이 거절됐습니다. PIXABAY_API_KEY를 확인하세요.');
        }
        if (!$response['ok'] || $status !== 200) {
            throw new RuntimeException('Pixabay 영상 검색에 실패했습니다. HTTP ' . $status);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) { throw new RuntimeException('Pixabay 검색 응답이 올바른 JSON이 아닙니다.'); }
        return $data;
    }

    /** Transport seam allows status/header handling to be tested without external requests. */
    protected function sendSearchRequest(array $params): array
    {
        $curl = curl_init('https://pixabay.com/api/videos/?' . http_build_query($params));
        $body = '';
        $headers = [];
        curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 40,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'wyhds-shorts/1.0 PHP-cURL', CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                if (preg_match('#^HTTP/\S+\s+\d+#i', $line)) { $headers = []; }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    if (in_array($name, ['content-type', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset', 'retry-after'], true)) {
                        $headers[$name] = trim($parts[1]);
                    }
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 4194304) { return 0; }
                $body .= $chunk;
                return strlen($chunk);
            }]);
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return ['ok' => $ok !== false, 'status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /** Check known cooldowns before paying for a new script; a known cached query is still usable. */
    public function assertAvailable(string $query = ''): void
    {
        if ($query !== '' && $this->readCache($this->cachePath($this->queryParams($query))) !== null) { return; }
        $this->withRateState(function (array &$state): void { $this->assertRateState($state); });
    }

    public function diagnostics(): array
    {
        return $this->withRateState(function (array &$state): array {
            return ['cache_hit' => $this->cacheHit, 'wait_seconds' => max(0, (int)($state['blocked_until'] ?? 0) - time()),
                'retry_at_utc' => empty($state['blocked_until']) ? null : gmdate('c', (int)$state['blocked_until']),
                'last_response' => $state['last_response'] ?? null];
        });
    }

    private function queryParams(string $query): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if ($query === '' || mb_strlen($query) > 100) { throw new RuntimeException('영상 검색어는 1~100자로 입력하세요.'); }
        return ['q' => $query, 'lang' => preg_match('/[가-힣]/u', $query) ? 'ko' : 'en',
            'video_type' => 'film', 'safesearch' => 'true', 'min_height' => 720, 'per_page' => 30];
    }

    private function cachePath(array $params): string
    {
        return $this->cacheRoot . '/' . hash('sha256', json_encode($params) . hash('sha256', $this->apiKey)) . '.json';
    }

    private function readCache(string $path): ?array
    {
        clearstatcache(true, $path);
        if (!is_file($path) || filemtime($path) <= time() - 86400) { return null; }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) && isset($data['hits']) && is_array($data['hits']) ? $data['hits'] : null;
    }

    private function ensureCacheRoot(): void
    {
        if (!is_dir($this->cacheRoot) && !@mkdir($this->cacheRoot, 0775, true) && !is_dir($this->cacheRoot)) {
            throw new RuntimeException('Pixabay 캐시 폴더를 만들 수 없습니다. 웹 실행 사용자의 쓰기 권한을 확인하세요.');
        }
    }

    private function withRateState(callable $callback)
    {
        $this->ensureCacheRoot();
        $path = $this->cacheRoot . '/rate-' . hash('sha256', $this->apiKey) . '.json';
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) { fclose($lock); }
            throw new RuntimeException('Pixabay 요청 제한 상태 파일을 잠글 수 없습니다. 캐시 폴더 권한을 확인하세요.');
        }
        $state = [];
        try {
            $state = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
            $state = is_array($state) ? $state : [];
            return $callback($state);
        } finally {
            try {
                if (file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR)) === false) {
                    throw new RuntimeException('Pixabay 요청 제한 상태를 저장하지 못했습니다.');
                }
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private function assertRateState(array $state): void
    {
        $until = (int)($state['blocked_until'] ?? 0);
        if ($until > time()) {
            throw new RuntimeException('Pixabay 재시도 대기 중입니다. 약 ' . ($until - time()) . '초 후 다시 시도하세요. 추가 Pixabay 요청은 보내지 않았습니다.');
        }
    }

    private function numericHeader(array $headers, string $name): ?int
    {
        return isset($headers[$name]) && ctype_digit($headers[$name]) ? (int)$headers[$name] : null;
    }

    private function retrySeconds(array $headers): int
    {
        $retry = $headers['retry-after'] ?? '';
        $retrySeconds = ctype_digit($retry) ? (int)$retry : ($retry !== '' ? max(0, (int)strtotime($retry) - time()) : 0);
        return max(1, $retrySeconds, $this->numericHeader($headers, 'x-ratelimit-reset') ?? 0,
            $retrySeconds === 0 && $this->numericHeader($headers, 'x-ratelimit-reset') === null ? 60 : 0);
    }

    /** Prefer unused portrait footage. Select a practical HD rendition instead of a huge 4K download. */
    public function select(array $hits, array $usedIds = []): ?array
    {
        $candidates = [];
        foreach ($hits as $hit) {
            if (empty($hit['id']) || (float)($hit['duration'] ?? 0) < 1
                || !$this->isPixabayUrl((string)($hit['pageURL'] ?? ''), ['pixabay.com', 'www.pixabay.com'])) { continue; }
            $variants = [];
            foreach ((array)($hit['videos'] ?? []) as $variant) {
                $width = (int)($variant['width'] ?? 0);
                $height = (int)($variant['height'] ?? 0);
                if ($width < 400 || $height < 720 || (int)($variant['size'] ?? 0) > self::MAX_BYTES
                    || !$this->isPixabayUrl((string)($variant['url'] ?? ''), ['cdn.pixabay.com'])) { continue; }
                $variant['_score'] = abs(log($height / 1080)) + max(0, ($width * $height - 2073600) / 2073600);
                $variants[] = $variant;
            }
            usort($variants, static fn ($a, $b) => $a['_score'] <=> $b['_score']);
            if ($variants === []) { continue; }
            $variant = $variants[0];
            $candidates[] = ['id' => (int)$hit['id'], 'page_url' => $hit['pageURL'],
                'user' => (string)($hit['user'] ?? 'Pixabay'), 'tags' => (string)($hit['tags'] ?? ''),
                'download_url' => $variant['url'], 'width' => (int)$variant['width'], 'height' => (int)$variant['height'],
                'duration' => (float)$hit['duration'],
                '_score' => (in_array((int)$hit['id'], $usedIds, true) ? 100 : 0)
                    + abs(log(($variant['width'] / $variant['height']) / (9 / 16)))];
        }
        usort($candidates, static fn ($a, $b) => $a['_score'] <=> $b['_score']);
        return $candidates[0] ?? null;
    }

    public function download(array $video, string $targetPath): void
    {
        if (!$this->isPixabayUrl((string)($video['download_url'] ?? ''), ['cdn.pixabay.com'])) {
            throw new RuntimeException('허용되지 않은 Pixabay 영상 주소입니다.');
        }
        $temp = $targetPath . '.part';
        $file = @fopen($temp, 'wb');
        if ($file === false) { throw new RuntimeException('영상 다운로드 파일을 만들 수 없습니다.'); }
        $bytes = 0;
        $curl = curl_init($video['download_url']);
        curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($file, &$bytes): int {
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_BYTES) { return 0; }
                $written = fwrite($file, $chunk);
                return $written === false ? 0 : $written;
            }]);
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        fclose($file);
        if ($ok === false || $status !== 200 || $bytes === 0 || !@rename($temp, $targetPath)) {
            @unlink($temp);
            throw new RuntimeException('Pixabay 영상 다운로드에 실패했습니다. HTTP ' . $status . ' (파일당 최대 80MB)');
        }
    }

    private function isPixabayUrl(string $url, array $hosts): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), $hosts, true)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']);
    }
}
