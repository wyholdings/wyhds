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
    private string $imageModel;

    public function __construct()
    {
        $this->apiKey = trim((string)($_ENV['OPENAI_API_KEY'] ?? ''));
        $this->baseUrl = rtrim((string)($_ENV['OPENAI_BASE_URL'] ?? 'https://api.openai.com/v1'), '/');
        $this->textModel = trim((string)($_ENV['OPENAI_MODEL'] ?? 'gpt-5'));
        $this->ttsModel = trim((string)($_ENV['OPENAI_TTS_MODEL'] ?? 'gpt-4o-mini-tts'));
        $this->ttsVoice = trim((string)($_ENV['OPENAI_TTS_VOICE'] ?? 'alloy'));
        $this->imageModel = trim((string)($_ENV['OPENAI_IMAGE_MODEL'] ?? 'gpt-image-1'));

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
  "caption_highlights": ["string", "string"],
  "scene_prompts": ["string", "string"],
  "hashtags": ["string", "string"],
  "visual_style": "string",
  "visual_anchor": "string",
  "camera_language": "string",
  "lighting_style": "string",
  "color_palette": "string",
  "cover_title": "string",
  "cover_subtitle": "string",
  "cta": "string"
}
Rules:
- narration should be long enough for the target duration, usually 350 to 650 Korean characters for 60 seconds.
- caption_lines must contain 6 to 8 short lines when target duration is 60 seconds.
- caption_highlights must contain the same number of items as caption_lines.
- Each caption_highlights item should be one short keyword or phrase from the matching caption line.
- scene_prompts must contain the same number of items as caption_lines.
- Each scene prompt must describe a single visual moment for an image model.
- Scene prompts must avoid letters, captions, logos, UI, watermark, collage, split screen.
- visual_anchor must define one consistent visual identity shared across all generated scenes.
- camera_language should describe framing and lens feel like close-up, medium shot, 35mm, shallow depth of field.
- lighting_style should describe lighting like soft cinematic light, rim light, moody contrast, daylight documentary.
- color_palette should describe a controlled color direction like teal and orange, warm neutral, muted blue and gold.
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
            'prompt_cache_key' => 'shorts-script-v3',
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
                            'caption_highlights',
                            'scene_prompts',
                            'hashtags',
                            'visual_style',
                            'visual_anchor',
                            'camera_language',
                            'lighting_style',
                            'color_palette',
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
                                'minItems' => 6,
                                'maxItems' => 8,
                            ],
                            'caption_highlights' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'minItems' => 6,
                                'maxItems' => 8,
                            ],
                            'scene_prompts' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'minItems' => 6,
                                'maxItems' => 8,
                            ],
                            'hashtags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'minItems' => 3,
                                'maxItems' => 6,
                            ],
                            'visual_style' => ['type' => 'string'],
                            'visual_anchor' => ['type' => 'string'],
                            'camera_language' => ['type' => 'string'],
                            'lighting_style' => ['type' => 'string'],
                            'color_palette' => ['type' => 'string'],
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
        $payload['caption_highlights'] = array_values(array_filter((array)($payload['caption_highlights'] ?? []), static fn ($line) => trim((string)$line) !== ''));
        $payload['scene_prompts'] = array_values(array_filter((array)($payload['scene_prompts'] ?? []), static fn ($line) => trim((string)$line) !== ''));
        $payload['hashtags'] = array_values(array_filter((array)($payload['hashtags'] ?? []), static fn ($tag) => trim((string)$tag) !== ''));

        if (($payload['narration'] ?? '') === '' || empty($payload['caption_lines']) || empty($payload['caption_highlights']) || empty($payload['scene_prompts'])) {
            throw new RuntimeException('스크립트 생성 결과가 비어 있습니다.');
        }

        $payload['_usage'] = $response['usage'] ?? null;
        $payload['_cached_tokens'] = (int)($response['usage']['input_tokens_details']['cached_tokens'] ?? 0);

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

    public function generateImage(string $prompt, string $targetPath): void
    {
        $response = $this->requestJson('/images/generations', [
            'model' => $this->imageModel,
            'prompt' => $prompt,
            'size' => '1024x1536',
        ]);

        $this->storeGeneratedImage($response, $targetPath);
    }

    public function generateImageFromReference(string $prompt, array $referenceImagePaths, string $targetPath): void
    {
        $imageFiles = [];
        foreach ($referenceImagePaths as $referenceImagePath) {
            if (!is_string($referenceImagePath) || !is_file($referenceImagePath)) {
                continue;
            }

            $imageFiles[] = new \CURLFile($referenceImagePath);
        }

        if ($imageFiles === []) {
            throw new RuntimeException('참조 이미지 파일을 찾을 수 없습니다.');
        }

        $fields = [
            'model' => $this->imageModel,
            'prompt' => $prompt,
            'size' => '1024x1536',
            'input_fidelity' => 'high',
        ];
        foreach ($imageFiles as $index => $imageFile) {
            $fields['image[' . $index . ']'] = $imageFile;
        }

        $response = $this->requestMultipart('/images/edits', $fields);

        $this->storeGeneratedImage($response, $targetPath);
    }

    private function storeGeneratedImage(array $response, string $targetPath): void
    {
        $imageData = $response['data'][0]['b64_json'] ?? null;
        if (!is_string($imageData) || $imageData === '') {
            throw new RuntimeException('이미지 생성 결과가 비어 있습니다.');
        }

        $binary = base64_decode($imageData, true);
        if ($binary === false) {
            throw new RuntimeException('이미지 디코딩에 실패했습니다.');
        }

        if (file_put_contents($targetPath, $binary) === false) {
            throw new RuntimeException('이미지 파일 저장에 실패했습니다.');
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

    private function requestMultipart(string $path, array $fields): array
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
            ],
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_TIMEOUT => 300,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('OpenAI 멀티파트 요청 실패: ' . $error);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI 멀티파트 응답을 JSON으로 해석하지 못했습니다.');
        }

        if ($httpCode >= 400) {
            $message = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);
            throw new RuntimeException('OpenAI 이미지 편집 실패: ' . $message);
        }

        return $decoded;
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
