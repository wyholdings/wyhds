<?php

// Offline controller tests with in-memory records and a counted generation fake.
namespace App\Models {
    class ShortsRenderModel
    {
        public static array $rows = [];
        public static bool $failCreate = false;
        public function findLatest(): array { return array_reverse(array_values(self::$rows)); }
        public function findById(int $id): ?array { return self::$rows[$id] ?? null; }
        public function create(array $data): int
        {
            if (self::$failCreate) { throw new \RuntimeException('test database failure'); }
            $id = count(self::$rows) + 1;
            self::$rows[$id] = $data + ['id' => $id, 'created_at' => '2026-11-03 12:00:00'];
            return $id;
        }
        public function update(int $id, array $data): bool
        {
            self::$rows[$id] = array_merge(self::$rows[$id], $data);
            return true;
        }
    }
}

namespace App\Services {
    class ShortsAutomationService
    {
        public static int $calls = 0;
        public static bool $fail = false;
        public static array $lastPayload = [];
        public static $duringGeneration;
        public function generate(array $payload): array
        {
            if (session_status() === PHP_SESSION_ACTIVE) {
                throw new \RuntimeException('Session must be saved and unlocked before paid work.');
            }
            self::$calls++;
            self::$lastPayload = $payload;
            if (self::$duringGeneration !== null) {
                $callback = self::$duringGeneration;
                self::$duringGeneration = null;
                $callback();
            }
            if (self::$fail) { throw new \RuntimeException('test generation failure'); }
            return [
                'output_dir' => '/test/output', 'cover_url' => '/test/cover.png',
                'audio_url' => '/test/voice.wav', 'video_url' => '/test/final.mp4',
                'scene_image_urls' => ['/test/scene_01.png'],
                'script' => ['video_title' => 'test title', 'narration' => 'test narration', 'hashtags' => ['test'],
                    '_media_source' => 'pixabay', '_video_sources' => [['id' => 123, 'page_url' => 'https://pixabay.com/videos/id-123/', 'user' => 'fixture creator', 'query' => 'bedroom']]],
            ];
        }
    }
}

namespace App\Controllers {
    function header($value, $replace = true, $status = 0)
    {
        $GLOBALS['shorts_test_headers'][] = ['value' => $value, 'status' => $status];
    }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    require __DIR__ . '/../vendor/autoload.php';
    require __DIR__ . '/../functions/csrf.php';

    function checkRefresh(bool $condition, string $label): void
    {
        if (!$condition) { throw new \RuntimeException('FAIL: ' . $label); }
        echo 'PASS: ' . $label . PHP_EOL;
    }

    function visitStudio(string $method, array $post = [], array $query = []): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = $post; $_GET = $query; $_FILES = [];
        $GLOBALS['shorts_test_headers'] = [];
        $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(__DIR__ . '/../views'));
        $twig->addGlobal('csrf_token', generate_csrf_token());
        ob_start();
        try {
            (new \App\Controllers\ShortsStudioController($twig))->studio();
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        return ['html' => $html, 'headers' => $GLOBALS['shorts_test_headers']];
    }

    function studioPost(array $page): array
    {
        preg_match('/name="request_token" value="([a-f0-9]{64})"/', $page['html'], $match);
        if (!isset($match[1])) { throw new \RuntimeException('No request token in form.'); }
        return ['csrf_token' => $_SESSION['csrf_token'], 'request_token' => $match[1], 'keyword' => 'test', 'duration' => 30, 'tone' => 'calm',
            'tts_provider' => 'google', 'voice' => \App\Services\GoogleSpeechClient::DEFAULT_VOICE, 'stock_keyword' => 'bedroom', 'bgm_mode' => 'default', 'bgm_volume' => 12];
    }

    ini_set('session.use_cookies', '0');
    session_cache_limiter('');
    session_id('shorts-refresh-test-' . bin2hex(random_bytes(8)));
    session_start();
    $_SESSION = []; $_ENV = [];
    $page = visitStudio('GET');
    $post = studioPost($page);
    checkRefresh(\App\Services\ShortsAutomationService::$calls === 0, 'initial GET does not generate');
    $response = visitStudio('POST', $post);
    checkRefresh($response['html'] === '' && $response['headers'] === [['value' => 'Location: /admin/shorts/studio?render=1', 'status' => 303]], 'successful POST redirects with 303 and no result body');
    checkRefresh(\App\Services\ShortsAutomationService::$calls === 1 && count(\App\Models\ShortsRenderModel::$rows) === 1, 'one accepted submission generates once');
    checkRefresh(\App\Services\ShortsAutomationService::$lastPayload['stock_keyword'] === 'bedroom', 'chosen footage query reaches generation');
    checkRefresh(\App\Services\ShortsAutomationService::$lastPayload['tts_provider'] === 'google'
        && \App\Services\ShortsAutomationService::$lastPayload['bgm_mode'] === 'default'
        && \App\Services\ShortsAutomationService::$lastPayload['bgm_volume'] === 12, 'Google choice and music settings reach generation');
    $savedResult = visitStudio('GET', [], ['render' => 1]);
    checkRefresh(strpos($savedResult['html'], 'https://pixabay.com/videos/id-123/') !== false
        && strpos($savedResult['html'], 'fixture creator') !== false, 'Pixabay original source and creator remain visible after redirect');
    foreach ([1, 2] as $refresh) {
        $page = visitStudio('GET', [], ['render' => 1]);
        checkRefresh(strpos($page['html'], '/test/final.mp4') !== false && strpos($page['html'], '/test/scene_01.png') !== false, 'result and scene previews survive refresh ' . $refresh);
    }
    visitStudio('POST', $post);
    checkRefresh(\App\Services\ShortsAutomationService::$calls === 1 && count(\App\Models\ShortsRenderModel::$rows) === 1, 'replayed POST does not generate or insert again');
    foreach ([[], array_merge($post, ['request_token' => 'unknown']), array_merge(studioPost($page), ['csrf_token' => 'invalid'])] as $invalid) {
        $response = visitStudio('POST', $invalid);
        checkRefresh(\App\Services\ShortsAutomationService::$calls === 1 && $response['headers'][0]['status'] === 303, 'invalid or old form cannot generate');
    }

    $post = studioPost(visitStudio('GET'));
    \App\Services\ShortsAutomationService::$fail = true;
    $response = visitStudio('POST', $post);
    checkRefresh($response['headers'][0]['value'] === 'Location: /admin/shorts/studio?render=2', 'failed generation also redirects');
    visitStudio('POST', $post);
    $page = visitStudio('GET', [], ['render' => 2]);
    visitStudio('GET', [], ['render' => 2]);
    checkRefresh(\App\Services\ShortsAutomationService::$calls === 2 && count(\App\Models\ShortsRenderModel::$rows) === 2 && strpos($page['html'], 'test generation failure') !== false, 'failed POST replay and refresh do not retry paid work');
    \App\Services\ShortsAutomationService::$fail = false;

    $firstTab = studioPost(visitStudio('GET'));
    $secondTab = studioPost(visitStudio('GET'));
    visitStudio('POST', $firstTab);
    visitStudio('POST', $secondTab);
    checkRefresh(\App\Services\ShortsAutomationService::$calls === 4, 'separate newly issued forms remain usable across tabs');

    $post = studioPost(visitStudio('GET'));
    \App\Services\ShortsAutomationService::$duringGeneration = static function () use ($post): void {
        $response = visitStudio('POST', $post);
        checkRefresh($response['headers'][0]['value'] === 'Location: /admin/shorts/studio?render=5', 'replay during generation points at the existing job');
    };
    visitStudio('POST', $post);
    checkRefresh(\App\Services\ShortsAutomationService::$calls === 5 && count(\App\Models\ShortsRenderModel::$rows) === 5, 'in-progress replay creates no second job');

    \App\Models\ShortsRenderModel::$failCreate = true;
    $post = studioPost(visitStudio('GET'));
    visitStudio('POST', $post);
    visitStudio('POST', $post);
    checkRefresh($_SESSION['shorts_requests'][$post['request_token']]['used'] && \App\Services\ShortsAutomationService::$calls === 5, 'request stays consumed even if record creation fails');
    \App\Models\ShortsRenderModel::$failCreate = false;
    for ($i = 0; $i < 40; $i++) { visitStudio('GET', [], ['render' => 1]); }
    checkRefresh(count($_SESSION['shorts_requests']) <= 32 && \App\Services\ShortsAutomationService::$calls === 5, 'repeated GET refreshes keep bounded token storage and never generate');
    session_destroy();
    echo 'All refresh checks passed. No API calls were made.' . PHP_EOL;
}
