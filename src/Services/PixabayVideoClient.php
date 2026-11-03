<?php

namespace App\Services;

use RuntimeException;

/** Bounded, user-triggered stock searches; response caching follows Pixabay's 24-hour requirement. */
class PixabayVideoClient
{
    private string $apiKey;
    private string $cacheRoot;
    private const MAX_BYTES = 83886080;

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
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if ($query === '' || mb_strlen($query) > 100) {
            throw new RuntimeException('영상 검색어는 1~100자로 입력하세요.');
        }
        $params = ['q' => $query, 'lang' => preg_match('/[가-힣]/u', $query) ? 'ko' : 'en',
            'video_type' => 'film', 'safesearch' => 'true', 'min_height' => 720, 'per_page' => 30];
        if (!is_dir($this->cacheRoot) && !@mkdir($this->cacheRoot, 0775, true) && !is_dir($this->cacheRoot)) {
            throw new RuntimeException('Pixabay 캐시 폴더를 만들 수 없습니다. 웹 실행 사용자의 쓰기 권한을 확인하세요.');
        }
        $path = $this->cacheRoot . '/' . hash('sha256', json_encode($params) . hash('sha256', $this->apiKey)) . '.json';
        // Also serialize identical searches from separate PHP workers.
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) { fclose($lock); }
            throw new RuntimeException('Pixabay 캐시를 잠글 수 없습니다. 캐시 폴더 권한을 확인하세요.');
        }
        try {
            clearstatcache(true, $path);
            if (is_file($path) && filemtime($path) > time() - 86400) {
                $cached = json_decode((string)file_get_contents($path), true);
                if (is_array($cached) && isset($cached['hits']) && is_array($cached['hits'])) {
                    return $cached['hits'];
                }
            }
            $data = $this->fetchSearch($params + ['key' => $this->apiKey]);
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
        $curl = curl_init('https://pixabay.com/api/videos/?' . http_build_query($params));
        $body = '';
        curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 40,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 4194304) { return 0; }
                $body .= $chunk;
                return strlen($chunk);
            }]);
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        // Never include request URLs or provider error bodies containing credentials in history/logs.
        if ($status === 429) {
            throw new RuntimeException('Pixabay 요청 한도를 초과했습니다. 잠시 후 새 요청으로 다시 시도하세요.');
        }
        if ($status === 400 || $status === 401 || $status === 403) {
            throw new RuntimeException('Pixabay 인증 또는 검색 요청이 거절됐습니다. PIXABAY_API_KEY를 확인하세요.');
        }
        if ($ok === false || $status !== 200) {
            throw new RuntimeException('Pixabay 영상 검색에 실패했습니다. HTTP ' . $status);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) { throw new RuntimeException('Pixabay 검색 응답이 올바른 JSON이 아닙니다.'); }
        return $data;
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
