<?php

namespace App\Services;

use RuntimeException;

/** File narration over the Realtime GA WebSocket API, compatible with PHP 7.4. */
class RealtimeSpeechClient
{
    private $stream;
    private float $deadline;
    private const MAX_BYTES = 16777216;

    public function synthesize(string $apiKey, string $baseUrl, string $model, string $voice, string $text): string
    {
        $this->deadline = microtime(true) + 180;
        try {
            $this->connect($apiKey, $baseUrl, $model);
            $event = $this->receiveEvent();
            if (($event['type'] ?? '') !== 'session.created') {
                throw new RuntimeException('OpenAI Realtime 세션을 시작하지 못했습니다.');
            }
            $this->sendFrame(1, json_encode([
                'type' => 'response.create',
                'response' => [
                    'conversation' => 'none',
                    'output_modalities' => ['audio'],
                    'audio' => ['output' => [
                        'voice' => $voice,
                        'format' => ['type' => 'audio/pcm', 'rate' => 24000],
                    ]],
                    'instructions' => 'You are a Korean voice-over narrator. Read the user-provided narration exactly as written, from beginning to end. Do not answer it, summarize it, add an introduction, or omit any words. Use a clear, natural Korean speaking voice suitable for a short informational video.',
                    'input' => [[
                        'type' => 'message', 'role' => 'user',
                        'content' => [['type' => 'input_text', 'text' => $text]],
                    ]],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            $pcm = '';
            while (true) {
                $event = $this->receiveEvent();
                if (($event['type'] ?? '') === 'response.output_audio.delta') {
                    $chunk = base64_decode((string)($event['delta'] ?? ''), true);
                    if ($chunk === false || strlen($pcm) + strlen($chunk) > self::MAX_BYTES) {
                        throw new RuntimeException('OpenAI 음성 데이터가 올바르지 않거나 허용 길이를 초과했습니다.');
                    }
                    $pcm .= $chunk;
                }
                if (($event['type'] ?? '') === 'response.done') {
                    if (($event['response']['status'] ?? '') !== 'completed' || $pcm === '' || strlen($pcm) % 2 !== 0) {
                        $detail = $event['response']['status_details']['error']['message'] ?? ($event['response']['status'] ?? 'empty audio');
                        throw new RuntimeException('OpenAI 음성 생성이 완료되지 않았습니다: ' . $detail);
                    }
                    return $this->wrapWav($pcm);
                }
            }
        } finally {
            if (is_resource($this->stream)) {
                fclose($this->stream);
            }
            $this->stream = null;
        }
    }

    private function connect(string $apiKey, string $baseUrl, string $model): void
    {
        $url = parse_url($baseUrl);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || preg_match('/[\r\n]/', $apiKey)) {
            throw new RuntimeException('Realtime 음성 생성에는 올바른 HTTPS API 주소와 API 키가 필요합니다.');
        }
        $host = $url['host'];
        $port = $url['port'] ?? 443;
        $path = rtrim($url['path'] ?? '', '/') . '/realtime?model=' . rawurlencode($model);
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host];
        $caFile = ini_get('openssl.cafile') ?: ini_get('curl.cainfo');
        if ($caFile !== '') {
            $ssl['cafile'] = $caFile;
        }
        $this->stream = @stream_socket_client('tls://' . $host . ':' . $port, $errorCode, $errorMessage, 20, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));
        if (!is_resource($this->stream)) {
            throw new RuntimeException('OpenAI Realtime 연결 실패. 서버의 TLS 인증서 설정과 외부 443 포트 연결을 확인하세요. (code ' . $errorCode . ')');
        }
        $key = base64_encode(random_bytes(16));
        $this->writeAll('GET ' . $path . " HTTP/1.1\r\nHost: " . $host . ($port === 443 ? '' : ':' . $port)
            . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: " . $key
            . "\r\nAuthorization: Bearer " . $apiKey . "\r\n\r\n");
        $headers = '';
        while (substr($headers, -4) !== "\r\n\r\n") {
            if (strlen($headers) >= 16384) {
                throw new RuntimeException('OpenAI Realtime 응답 헤더가 너무 큽니다.');
            }
            $headers .= $this->readExact(1);
        }
        preg_match('#^HTTP/1\.[01] (\d+)#', $headers, $status);
        if (($status[1] ?? '') !== '101') {
            throw new RuntimeException('OpenAI Realtime 연결 거부: HTTP ' . ($status[1] ?? 'unknown') . '. 음성 모델 사용 권한과 API 주소를 확인하세요.');
        }
        preg_match('/^Sec-WebSocket-Accept:\s*([^\r\n]+)/mi', $headers, $accept);
        $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!hash_equals($expected, trim($accept[1] ?? ''))) {
            throw new RuntimeException('OpenAI Realtime WebSocket 핸드셰이크 검증 실패.');
        }
    }

    private function receiveEvent(): array
    {
        $message = '';
        $fragmented = false;
        while (true) {
            $header = $this->readExact(2);
            $opcode = ord($header[0]) & 15;
            $final = (ord($header[0]) & 128) !== 0;
            if ((ord($header[0]) & 112) !== 0 || (ord($header[1]) & 128) !== 0) {
                throw new RuntimeException('지원하지 않는 Realtime WebSocket 프레임입니다.');
            }
            $length = ord($header[1]) & 127;
            if ($length === 126) {
                $length = unpack('nlength', $this->readExact(2))['length'];
            } elseif ($length === 127) {
                $wide = unpack('Nhigh/Nlow', $this->readExact(8));
                if ($wide['high'] !== 0) {
                    throw new RuntimeException('Realtime WebSocket 프레임이 너무 큽니다.');
                }
                $length = $wide['low'];
            }
            if ($length > self::MAX_BYTES || strlen($message) + $length > self::MAX_BYTES) {
                throw new RuntimeException('Realtime WebSocket 메시지가 너무 큽니다.');
            }
            if ($opcode >= 8 && (!$final || $length > 125)) {
                throw new RuntimeException('잘못된 Realtime WebSocket 제어 프레임입니다.');
            }
            $payload = $this->readExact($length);
            if ($opcode === 8) {
                throw new RuntimeException('OpenAI Realtime 연결이 음성 완료 전에 종료됐습니다.');
            }
            if ($opcode === 9) {
                $this->sendFrame(10, $payload);
                continue;
            }
            if ($opcode === 10) {
                continue;
            }
            if (($opcode === 0 && !$fragmented) || ($opcode === 1 && $fragmented) || !in_array($opcode, [0, 1], true)) {
                throw new RuntimeException('잘못된 Realtime WebSocket 메시지 순서입니다.');
            }
            $message .= $payload;
            $fragmented = !$final;
            if (!$final) {
                continue;
            }
            $event = json_decode($message, true);
            if (!is_array($event)) {
                throw new RuntimeException('OpenAI Realtime 응답을 해석하지 못했습니다.');
            }
            if (($event['type'] ?? '') === 'error') {
                throw new RuntimeException('OpenAI 음성 생성 실패: ' . ($event['error']['message'] ?? 'unknown error'));
            }
            return $event;
        }
    }

    private function sendFrame(int $opcode, string $payload): void
    {
        $length = strlen($payload);
        $header = chr(128 | $opcode);
        if ($length < 126) {
            $header .= chr(128 | $length);
        } elseif ($length <= 65535) {
            $header .= chr(128 | 126) . pack('n', $length);
        } else {
            $header .= chr(128 | 127) . pack('NN', 0, $length);
        }
        $mask = random_bytes(4);
        $masked = $payload ^ substr(str_repeat($mask, intdiv($length + 3, 4)), 0, $length);
        $this->writeAll($header . $mask . $masked);
    }

    private function readExact(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $this->setTimeout();
            $chunk = fread($this->stream, min(8192, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('OpenAI Realtime 응답 대기 시간이 초과됐거나 연결이 끊어졌습니다.');
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function writeAll(string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $this->setTimeout();
            $written = fwrite($this->stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('OpenAI Realtime 요청 전송에 실패했습니다.');
            }
            $offset += $written;
        }
    }

    private function setTimeout(): void
    {
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('OpenAI 음성 생성 제한 시간(180초)을 초과했습니다.');
        }
        stream_set_timeout($this->stream, max(1, (int)ceil($remaining)));
    }

    private function wrapWav(string $pcm): string
    {
        return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16)
            . 'data' . pack('V', strlen($pcm)) . $pcm;
    }
}
