<?php

namespace App\Services;

use RuntimeException;

class GoogleSpeechClient
{
    public const DEFAULT_VOICE = 'ko-KR-Chirp3-HD-Kore';
    public const VOICES = [
        'ko-KR-Chirp3-HD-Kore' => 'Kore · 여성',
        'ko-KR-Chirp3-HD-Aoede' => 'Aoede · 여성',
        'ko-KR-Chirp3-HD-Charon' => 'Charon · 남성',
        'ko-KR-Chirp3-HD-Fenrir' => 'Fenrir · 남성',
    ];

    public static function configuration(): array
    {
        return ['provider' => trim((string)($_ENV['SHORTS_TTS_PROVIDER'] ?? '')) ?: 'google',
            'voice' => trim((string)($_ENV['GOOGLE_TTS_VOICE'] ?? '')) ?: self::DEFAULT_VOICE];
    }

    public function synthesizeNarration(array $paragraphs, string $target, string $voice, float $speed = 1.0): array
    {
        $key = $this->apiKey();
        if (!isset(self::VOICES[$voice]) || count($paragraphs) < 1 || count($paragraphs) > 8) {
            throw new RuntimeException('Google 한국어 보이스 또는 문단 구성이 올바르지 않습니다.');
        }
        // Validate every paragraph before the first request, so bad input cannot waste partial synthesis.
        foreach ($paragraphs as $text) {
            if (!is_string($text) || trim($text) === '' || strlen($text) > 4500) { throw new RuntimeException('Google 음성 문단은 비어 있지 않은 4500바이트 이내 텍스트여야 합니다.'); }
        }
        $pcm = '';
        $segments = [];
        foreach ($paragraphs as $index => $text) {
            $result = $this->request(['input' => ['text' => $text], 'voice' => ['languageCode' => 'ko-KR', 'name' => $voice],
                'audioConfig' => ['audioEncoding' => 'LINEAR16', 'sampleRateHertz' => 24000, 'speakingRate' => max(0.85, min(1.15, $speed))]], $key);
            $wav = base64_decode((string)($result['audioContent'] ?? ''), true);
            if ($wav === false) { throw new RuntimeException('Google 음성 데이터를 해석하지 못했습니다.'); }
            $part = NarrationPcm::trimSilence(NarrationPcm::decodeWav($wav));
            if ($index > 0) { $pcm .= str_repeat("\0", 4800); }
            $start = strlen($pcm) / 48000;
            $pcm .= $part;
            if (strlen($pcm) > 16777216) { throw new RuntimeException('Google 음성의 최대 길이를 초과했습니다.'); }
            $segments[] = ['text' => $text, 'spoken_text' => $text, 'start' => $start, 'end' => strlen($pcm) / 48000];
        }
        if (file_put_contents($target, NarrationPcm::encodeWav($pcm)) === false) { throw new RuntimeException('Google 음성 파일을 저장하지 못했습니다.'); }
        return ['segments' => $segments, 'duration' => strlen($pcm) / 48000];
    }

    protected function request(array $payload, string $key): array
    {
        $data = $this->send('POST', 'text:synthesize', $payload, $key);
        if (!is_string($data['audioContent'] ?? null)) { throw new RuntimeException('Google TTS 응답에 음성이 없습니다.'); }
        return $data;
    }

    public function checkVoice(string $voice): void
    {
        if (!isset(self::VOICES[$voice])) { throw new RuntimeException('지원하지 않는 Google 한국어 보이스입니다.'); }
        $data = $this->send('GET', 'voices?languageCode=ko-KR', [], $this->apiKey());
        foreach ((array)($data['voices'] ?? []) as $item) {
            if (($item['name'] ?? '') === $voice) { return; }
        }
        throw new RuntimeException('Google 계정에서 선택한 한국어 보이스를 찾지 못했습니다: ' . $voice);
    }

    private function apiKey(): string
    {
        $key = trim((string)($_ENV['GOOGLE_TTS_API_KEY'] ?? ''));
        if ($key === '' || preg_match('/[\r\n]/', $key)) { throw new RuntimeException('서버 .env에 GOOGLE_TTS_API_KEY를 설정하세요. Google Cloud Text-to-Speech API 키가 필요합니다.'); }
        return $key;
    }

    protected function send(string $method, string $path, array $payload, string $key): array
    {
        $curl = curl_init('https://texttospeech.googleapis.com/v1/' . $path);
        $body = '';
        curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 90,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'X-Goog-Api-Key: ' . $key],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 16777216) { return 0; }
                $body .= $chunk;
                return strlen($chunk);
            }]);
        if ($method === 'POST') {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        }
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($status === 429) { throw new RuntimeException('Google TTS 요청 한도에 도달했습니다. Cloud 콘솔의 할당량을 확인하세요. 다른 유료 음성으로 자동 전환하지 않았습니다.'); }
        if ($ok === false || $status !== 200) {
            throw new RuntimeException('Google TTS 요청 실패: HTTP ' . $status . '. Cloud Text-to-Speech API 사용 설정, 결제 연결, API 키 제한을 확인하세요.');
        }
        $data = json_decode($body, true);
        if (!is_array($data)) { throw new RuntimeException('Google TTS 응답을 해석하지 못했습니다.'); }
        return $data;
    }
}
