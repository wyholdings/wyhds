<?php

namespace App\Services;

use RuntimeException;

class OpenAIClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $textModel;
    private string $ttsModel;
    private string $ttsVoice;

    public function __construct()
    {
        $this->apiKey = trim((string)($_ENV['OPENAI_API_KEY'] ?? ''));
        $this->baseUrl = rtrim((string)($_ENV['OPENAI_BASE_URL'] ?? 'https://api.openai.com/v1'), '/');
        $this->textModel = trim((string)($_ENV['OPENAI_MODEL'] ?? 'gpt-5'));
        $this->ttsModel = trim((string)($_ENV['OPENAI_TTS_MODEL'] ?? 'gpt-4o-mini-tts'));
        $this->ttsVoice = trim((string)($_ENV['OPENAI_TTS_VOICE'] ?? 'alloy'));

        if ($this->apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY가 설정되어 있지 않습니다.');
        }
    }

    public function generateShortsScript(array $context): array
    {
        $systemPrompt = <<<PROMPT
You are a YouTube Shorts script system.
Return valid JSON only.
Write in Korean.
Keep the video highly clickable but policy-safe.
The JSON schema is:
{
  "video_title": "string",
  "hook": "string",
  "narration": "string",
  "caption_lines": ["string", "string"],
  "hashtags": ["string", "string"],
  "visual_style": "string",
  "cover_title": "string",
  "cover_subtitle": "string",
  "cta": "string"
}
Rules:
- narration must be 120 to 220 Korean characters.
- caption_lines must contain 4 to 8 short lines.
- hashtags must contain 3 to 6 items without # symbols.
- cover_title must be 18 characters or less.
- cover_subtitle must be 24 characters or less.
- No markdown.
PROMPT;

        $projectBlock = $context['project']
            ? json_encode($context['project'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : 'null';

        $userPrompt = <<<PROMPT
keyword: {$context['keyword']}
tone: {$context['tone']}
target_duration_seconds: {$context['duration']}
project_template: {$projectBlock}

Generate one complete YouTube Shorts package optimized for a single vertical video.
PROMPT;

        $response = $this->requestJson('/responses', [
            'model' => $this->textModel,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => $systemPrompt],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $userPrompt],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'shorts_script',
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'video_title',
                            'hook',
                            'narration',
                            'caption_lines',
                            'hashtags',
                            'visual_style',
                            'cover_title',
                            'cover_subtitle',
                            'cta',
                        ],
                        'properties' => [
                            'video_title' => ['type' => 'string'],
                            'hook' => ['type' => 'string'],
                            'narration' => ['type' => 'string'],
                            'caption_lines' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'minItems' => 4,
                                'maxItems' => 8,
                            ],
                            'hashtags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'minItems' => 3,
                                'maxItems' => 6,
                            ],
                            'visual_style' => ['type' => 'string'],
                            'cover_title' => ['type' => 'string'],
                            'cover_subtitle' => ['type' => 'string'],
                            'cta' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ]);

        $text = $this->extractResponseText($response);
        $payload = $this->decodeJsonObject($text);

        $payload['caption_lines'] = array_values(array_filter((array)($payload['caption_lines'] ?? []), static fn ($line) => trim((string)$line) !== ''));
        $payload['hashtags'] = array_values(array_filter((array)($payload['hashtags'] ?? []), static fn ($tag) => trim((string)$tag) !== ''));

        if (($payload['narration'] ?? '') === '' || empty($payload['caption_lines'])) {
            throw new RuntimeException('스크립트 생성 결과가 비어 있습니다.');
        }

        return $payload;
    }

    public function synthesizeSpeech(string $text, string $targetPath, ?string $voice = null): void
    {
        $binary = $this->requestBinary('/audio/speech', [
            'model' => $this->ttsModel,
            'voice' => $voice ?: $this->ttsVoice,
            'input' => $text,
            'format' => 'aac',
        ]);

        if (file_put_contents($targetPath, $binary) === false) {
            throw new RuntimeException('음성 파일 저장에 실패했습니다.');
        }
    }

    private function requestJson(string $path, array $payload): array
    {
        $response = $this->sendRequest($path, $payload, false);
        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI 응답을 JSON으로 해석하지 못했습니다.');
        }

        if (isset($decoded['error']['message'])) {
            throw new RuntimeException('OpenAI API 오류: ' . $decoded['error']['message']);
        }

        return $decoded;
    }

    private function requestBinary(string $path, array $payload): string
    {
        $response = $this->sendRequest($path, $payload, true);

        $possibleJson = json_decode($response, true);
        if (is_array($possibleJson) && isset($possibleJson['error']['message'])) {
            throw new RuntimeException('OpenAI API 오류: ' . $possibleJson['error']['message']);
        }

        return $response;
    }

    private function sendRequest(string $path, array $payload, bool $binary): string
    {
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            throw new RuntimeException('cURL 초기화에 실패했습니다.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 180,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('OpenAI 요청 실패: ' . $error);
        }

        if ($httpCode >= 400 && !$binary) {
            $decoded = json_decode($response, true);
            $message = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);
            throw new RuntimeException('OpenAI 요청 실패: ' . $message);
        }

        if ($httpCode >= 400 && $binary) {
            $decoded = json_decode($response, true);
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? ('HTTP ' . $httpCode)) : ('HTTP ' . $httpCode);
            throw new RuntimeException('OpenAI 음성 생성 실패: ' . $message);
        }

        return $response;
    }

    private function extractResponseText(array $response): string
    {
        if (!empty($response['output_text']) && is_string($response['output_text'])) {
            return trim($response['output_text']);
        }

        $chunks = [];
        foreach ((array)($response['output'] ?? []) as $item) {
            foreach ((array)($item['content'] ?? []) as $content) {
                if (isset($content['text']) && is_string($content['text'])) {
                    $chunks[] = $content['text'];
                }
            }
        }

        $text = trim(implode("\n", $chunks));
        if ($text === '') {
            throw new RuntimeException('OpenAI 텍스트 응답이 비어 있습니다.');
        }

        return $text;
    }

    private function decodeJsonObject(string $text): array
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/su', $text, $matches) !== 1) {
            throw new RuntimeException('JSON 응답을 찾지 못했습니다.');
        }

        $decoded = json_decode($matches[0], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON 응답 파싱에 실패했습니다.');
        }

        return $decoded;
    }
}
