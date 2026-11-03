<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';

use App\Services\GoogleSpeechClient;
use App\Services\NarrationPcm;

class GoogleSpeechFixture extends GoogleSpeechClient
{
    public array $requests = [];
    public array $responses = [];
    protected function send(string $method, string $path, array $payload, string $key): array
    {
        $this->requests[] = ['method' => $method, 'path' => $path, 'payload' => $payload];
        if ($method === 'GET') { return ['voices' => [['name' => self::DEFAULT_VOICE]]]; }
        return array_shift($this->responses) ?? [];
    }
}
function googleCheck(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'PASS: ' . $label . PHP_EOL;
}
function googleReject(callable $callback): void
{
    $rejected = false;
    try { $callback(); } catch (RuntimeException $error) { $rejected = true; }
    googleCheck($rejected, 'bad input or response is rejected');
}
$target = tempnam(sys_get_temp_dir(), 'google-voice-');
try {
    $client = new GoogleSpeechFixture();
    $_ENV['GOOGLE_TTS_API_KEY'] = '';
    googleReject(static function () use ($client) { $client->checkVoice(GoogleSpeechClient::DEFAULT_VOICE); });
    googleCheck($client->requests === [], 'missing key fails before network access');
    $_ENV['GOOGLE_TTS_API_KEY'] = 'fake-key-for-offline-fixtures';
    $client->checkVoice(GoogleSpeechClient::DEFAULT_VOICE);
    googleCheck(count($client->requests) === 1 && $client->requests[0]['method'] === 'GET', 'credential and voice check does not synthesize speech');
    $pcm1 = str_repeat(pack('v', 1000), 24000);
    $pcm2 = str_repeat(pack('v', 2000), 48000);
    $wave1 = NarrationPcm::encodeWav($pcm1);
    // WAV chunks can include metadata and odd-byte padding before fmt/data.
    $wave2 = substr(NarrationPcm::encodeWav($pcm2), 0, 12) . 'JUNK' . pack('V', 3) . 'abc' . "\0" . substr(NarrationPcm::encodeWav($pcm2), 12);
    googleCheck(NarrationPcm::decodeWav($wave2) === $pcm2, 'Google WAV decoding tolerates metadata instead of assuming a 44-byte header');
    $client->responses = [['audioContent' => base64_encode($wave1)], ['audioContent' => base64_encode($wave2)]];
    $text = ['침대는 매일 사용하죠.', '이불을 자주 세탁하는 것도 도움이 돼요.'];
    $speech = $client->synthesizeNarration($text, $target, GoogleSpeechClient::DEFAULT_VOICE, 0.9);
    googleCheck(abs($speech['duration'] - 3.1) < 0.0001 && abs($speech['segments'][1]['start'] - 1.1) < 0.0001, 'unequal paragraph audio lengths and brief gap determine actual timing');
    googleCheck(array_column($speech['segments'], 'text') === $text && strlen(NarrationPcm::decodeWav(file_get_contents($target))) === 148800, 'spoken paragraphs and joined PCM are preserved');
    $payload = $client->requests[1]['payload'];
    googleCheck($payload['voice']['languageCode'] === 'ko-KR' && $payload['voice']['name'] === GoogleSpeechClient::DEFAULT_VOICE
        && $payload['audioConfig']['speakingRate'] === 0.9 && $payload['input']['text'] === $text[0], 'Korean voice, pace and literal narration go to Google');
    $before = count($client->requests);
    googleReject(static function () use ($client, $target) { $client->synthesizeNarration(['valid', str_repeat('가', 1501)], $target, GoogleSpeechClient::DEFAULT_VOICE); });
    googleCheck(count($client->requests) === $before, 'all paragraph limits are checked before any partial billable synthesis');
    foreach (['broken WAV', substr($wave1, 0, -2), substr_replace($wave1, pack('v', 2), 22, 2)] as $invalid) {
        googleReject(static function () use ($invalid) { NarrationPcm::decodeWav($invalid); });
    }
    $client->responses = [['audioContent' => 'not-valid-base64!']];
    googleReject(static function () use ($client, $target) { $client->synthesizeNarration(['실패 응답 확인'], $target, GoogleSpeechClient::DEFAULT_VOICE); });
    googleReject(static function () { NarrationPcm::trimSilence(str_repeat("\0", 48000)); });
} finally { unlink($target); }
echo 'Google TTS fixtures passed. No API requests were made.' . PHP_EOL;
