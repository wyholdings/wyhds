<?php

// Offline regression checks: php tests/shorts_regression.php
namespace App\Services {
    // Simulate short socket writes without touching the receive fixture.
    function fwrite($stream, $data)
    {
        $length = min(17, strlen($data));
        $GLOBALS['shorts_socket_output'] .= substr($data, 0, $length);
        return $length;
    }
}

namespace {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
    require __DIR__ . '/../vendor/autoload.php';

    function verifyShorts(bool $condition, string $name): void
    {
        if (!$condition) {
            throw new \RuntimeException('FAIL: ' . $name);
        }
        echo 'PASS: ' . $name . PHP_EOL;
    }

    function serverFrame(string $payload, int $opcode = 1, bool $final = true): string
    {
        $header = chr(($final ? 128 : 0) | $opcode);
        $length = strlen($payload);
        return $header . ($length < 126 ? chr($length) : ($length <= 65535 ? chr(126) . pack('n', $length) : chr(127) . pack('NN', 0, $length))) . $payload;
    }

    function shortsProperty(\ReflectionClass $reflection, string $name): \ReflectionProperty
    {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        return $property;
    }

    function shortsMethod(\ReflectionClass $reflection, string $name): \ReflectionMethod
    {
        $method = $reflection->getMethod($name);
        $method->setAccessible(true);
        return $method;
    }

    function speechFixture(string $frames): array
    {
        $speech = new \App\Services\RealtimeSpeechClient();
        $reflection = new \ReflectionClass($speech);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $frames);
        rewind($stream);
        shortsProperty($reflection, 'stream')->setValue($speech, $stream);
        shortsProperty($reflection, 'deadline')->setValue($speech, microtime(true) + 5);
        return [$speech, $reflection, $stream];
    }

    $_ENV = ['OPENAI_API_KEY' => 'offline-test'];
    $models = \App\Services\OpenAIClient::modelConfiguration();
    verifyShorts($models['text_model'] === 'gpt-6-luna' && $models['tts_model'] === 'gpt-realtime-2.1-mini', 'current text and speech defaults');
    verifyShorts($models['tts_voice'] === 'marin' && in_array('cedar', \App\Services\OpenAIClient::VOICES, true), 'supported Realtime voices');

    foreach ([10, 300, 70000] as $size) {
        $payload = json_encode(['type' => 'test', 'value' => str_repeat('a', $size)]);
        [$speech, $reflection, $stream] = speechFixture(serverFrame($payload));
        try {
            $event = shortsMethod($reflection, 'receiveEvent')->invoke($speech);
            verifyShorts(strlen($event['value']) === $size, 'WebSocket receive length ' . $size);
            $GLOBALS['shorts_socket_output'] = '';
            shortsMethod($reflection, 'sendFrame')->invoke($speech, 1, $payload);
            $frame = $GLOBALS['shorts_socket_output'];
            $tag = ord($frame[1]) & 127;
            $offset = $tag === 126 ? 4 : ($tag === 127 ? 10 : 2);
            $mask = substr($frame, $offset, 4);
            $encoded = substr($frame, $offset + 4);
            $decoded = $encoded ^ substr(str_repeat($mask, intdiv(strlen($encoded) + 3, 4)), 0, strlen($encoded));
            verifyShorts((ord($frame[1]) & 128) !== 0 && $decoded === $payload, 'masked frame survives partial writes ' . $size);
        } finally {
            fclose($stream);
        }
    }

    $json = json_encode(['type' => 'fragmented']);
    [$speech, $reflection, $stream] = speechFixture(serverFrame(substr($json, 0, 8), 1, false) . serverFrame('ping', 9) . serverFrame(substr($json, 8), 0));
    try {
        $GLOBALS['shorts_socket_output'] = '';
        verifyShorts(shortsMethod($reflection, 'receiveEvent')->invoke($speech)['type'] === 'fragmented', 'fragmented JSON with interleaved ping');
        verifyShorts((ord($GLOBALS['shorts_socket_output'][0]) & 15) === 10, 'ping receives pong');
        $pcm = str_repeat("\x01\x00", 24000);
        $wav = shortsMethod($reflection, 'wrapWav')->invoke($speech, $pcm);
        verifyShorts(substr($wav, 0, 4) === 'RIFF' && substr($wav, 8, 4) === 'WAVE' && strlen($wav) === 48044, 'PCM audio becomes a valid-sized WAV');
        verifyShorts(unpack('Vrate', substr($wav, 24, 4))['rate'] === 24000, 'WAV sample rate matches Realtime PCM');
    } finally {
        fclose($stream);
    }

    foreach ([serverFrame('{broken'), serverFrame(json_encode(['type' => 'error', 'error' => ['message' => 'test API error']])), serverFrame('', 8), serverFrame('orphan continuation', 0)] as $fixture) {
        [$speech, $reflection, $stream] = speechFixture($fixture);
        try {
            $threw = false;
            try {
                shortsMethod($reflection, 'receiveEvent')->invoke($speech);
            } catch (\RuntimeException $error) {
                $threw = true;
            }
            verifyShorts($threw, 'invalid or failed WebSocket response is rejected');
        } finally {
            fclose($stream);
        }
    }

    $twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader(__DIR__ . '/../views'));
    $html = $twig->render('admin/shorts/studio.html.twig', [
        'renders' => [['id' => 1, 'keyword' => 'test', 'created_at' => '2026-11-03 12:00:00', 'status' => 'completed', 'video_url' => '/test/final.mp4']],
        'flash' => null, 'result' => null,
        'form' => ['keyword' => '', 'tone' => 'calm', 'duration' => 30, 'voice' => 'marin'],
        'voices' => \App\Services\OpenAIClient::VOICES,
        'openai_model' => $models['text_model'], 'tts_model' => $models['tts_model'], 'image_model' => $models['image_model'],
        'output_root' => '/public/generated/shorts', 'ffmpeg_path' => '/usr/bin/ffmpeg', 'bgm_path' => '',
    ]);
    verifyShorts(strpos($html, 'name="project_id"') === false && strpos($html, '/admin/shorts/list') === false && strpos($html, '/admin/shorts/add') === false, 'legacy Shorts UI removed');
    verifyShorts(strpos($html, '/test/final.mp4') !== false && strpos($html, '/admin/project/list') !== false, 'history and unrelated business projects remain accessible');
    $router = new \App\Router($twig);
    require __DIR__ . '/../routes/web.php';
    $routes = shortsProperty(new \ReflectionClass($router), 'routes')->getValue($router);
    $shortsRoutes = array_filter($routes, static fn (array $route): bool => strpos($route['path'], '/admin/shorts/') === 0);
    verifyShorts(count($shortsRoutes) === 2, 'only studio GET and POST routes remain');
    foreach ($shortsRoutes as $route) {
        verifyShorts($route['path'] === '/admin/shorts/studio' && method_exists($route['handler'][0], $route['handler'][1]), 'studio handler exists');
    }

    if (in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
        foreach ([false, true] as $legacySchema) {
            $db = new \PDO('sqlite::memory:');
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->sqliteCreateFunction('NOW', static fn (): string => '2026-11-03 12:00:00');
            $db->exec('CREATE TABLE shorts_renders (id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . ($legacySchema ? 'project_id INTEGER DEFAULT NULL, ' : '')
                . 'keyword TEXT, status TEXT, error_message TEXT, output_dir TEXT, cover_url TEXT, audio_url TEXT, video_url TEXT, script_json TEXT, created_at TEXT, updated_at TEXT)');
            $reflection = new \ReflectionClass(\App\Models\ShortsRenderModel::class);
            $model = $reflection->newInstanceWithoutConstructor();
            shortsProperty($reflection, 'db')->setValue($model, $db);
            $data = ['keyword' => 'test', 'status' => 'processing', 'error_message' => null, 'output_dir' => null, 'cover_url' => null, 'audio_url' => null, 'video_url' => null, 'script_json' => null];
            $id = $model->create($data);
            if ($legacySchema) {
                $db->exec('UPDATE shorts_renders SET project_id = 42 WHERE id = ' . $id);
            }
            $data['status'] = 'completed';
            $data['video_url'] = '/test/final.mp4';
            $model->update($id, $data);
            verifyShorts(count($model->findLatest()) === 1 && $model->findById($id)['video_url'] === '/test/final.mp4', 'history works without project table: ' . ($legacySchema ? 'legacy schema' : 'new schema'));
            if ($legacySchema) {
                verifyShorts((int)$model->findById($id)['project_id'] === 42, 'existing project linkage is preserved in stored history');
            }
        }
    } else {
        echo 'SKIP: optional SQLite history tests (enable pdo_sqlite to run).' . PHP_EOL;
    }
    echo 'All offline Shorts checks passed. No API calls were made.' . PHP_EOL;
}
