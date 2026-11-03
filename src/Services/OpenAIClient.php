<?php

namespace App\Services;

use RuntimeException;

class OpenAIClient
{
    public const DEFAULT_TEXT_MODEL = 'gpt-6-luna';
    public const DEFAULT_IMAGE_MODEL = 'gpt-image-2.5-sunburst';
    public const DEFAULT_TTS_MODEL = 'gpt-realtime-2.1-mini';
    public const DEFAULT_TTS_VOICE = 'marin';
    public const VOICES = ['marin', 'cedar', 'alloy', 'ash', 'ballad', 'coral', 'echo', 'sage', 'shimmer', 'verse'];

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
        $config = self::modelConfiguration();
        $this->textModel = $config['text_model'];
        $this->ttsModel = $config['tts_model'];
        $this->ttsVoice = $config['tts_voice'];
        $this->imageModel = $config['image_model'];

        if ($this->apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY가 설정되어 있지 않습니다.');
        }
    }

    public static function modelConfiguration(): array
    {
        return [
            'text_model' => trim((string)($_ENV['OPENAI_MODEL'] ?? '')) ?: self::DEFAULT_TEXT_MODEL,
            'image_model' => trim((string)($_ENV['OPENAI_IMAGE_MODEL'] ?? '')) ?: self::DEFAULT_IMAGE_MODEL,
            'tts_model' => trim((string)($_ENV['OPENAI_TTS_MODEL'] ?? '')) ?: self::DEFAULT_TTS_MODEL,
            'tts_voice' => trim((string)($_ENV['OPENAI_TTS_VOICE'] ?? '')) ?: self::DEFAULT_TTS_VOICE,
        ];
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
  "narration_segments": ["string", "string"],
  "stock_queries": ["string", "string"],
  "stock_fallback_query": "string",
  "hashtags": ["string", "string"],
  "cover_title": "string",
  "cover_subtitle": "string",
  "cta": "string"
}
Rules:
- narration_segments must contain 6 to 8 spoken paragraphs, each one or two complete, connected sentences.
- The combined narration should contain about 5 to 6 Korean characters per target second: about 150-180 for 30 seconds, 300-360 for 60 seconds, 450-540 for 90 seconds.
- Write natural spoken Korean with conversational endings such as 해요, 하죠, 있어요. Avoid stiff translated wording, list labels, repeated hooks, and excessive exclamation marks.
- Use readable spoken forms for numbers and abbreviations. No emoji, markdown, stage directions, or pronunciation brackets in narration.
- Start with a concise, specific hook. Connect each thought smoothly. End with a useful takeaway, without a generic thank-you or forced subscription request.
- Each paragraph must be understandable when read aloud; do not split a sentence between paragraphs.
- stock_queries must match narration_segments in number and order. Each is a simple English Pixabay footage search of 1 to 3 words, at most 100 characters. Choose concrete, commonly filmed subjects relevant to the paragraph, not abstract concepts or elaborate visual descriptions.
- stock_fallback_query is one broad English footage search of 1 to 3 words relevant to the entire topic. For dust mites, use bedroom, bedding, washing sheets; do not assume microscopic mite footage exists. Never use unrelated generic scenery as filler.
- hashtags must contain 3 to 6 items without # symbols.
- cover_title must be 18 characters or less.
- cover_subtitle must be 24 characters or less.
- No markdown.
PROMPT;

        $userPrompt = <<<PROMPT
keyword: {$context['keyword']}
tone: {$context['tone']}
target_duration_seconds: {$context['duration']}
Generate one complete YouTube Shorts package optimized for a single vertical video.
PROMPT;

        $response = $this->requestJson('/responses', [
            'model' => $this->textModel,
            'prompt_cache_key' => 'shorts-script-v6-pixabay',
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
                            'narration_segments',
                            'stock_queries',
                            'stock_fallback_query',
                            'hashtags',
                            'cover_title',
                            'cover_subtitle',
                            'cta',
                        ],
                        'properties' => [
                            'video_title' => ['type' => 'string'],
                            'hook' => ['type' => 'string'],
                            'narration_segments' => [
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
                            'stock_queries' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 6, 'maxItems' => 8],
                            'stock_fallback_query' => ['type' => 'string'],
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

        $payload['narration_segments'] = array_values(array_filter(array_map(static fn ($line) => trim((string)$line), (array)($payload['narration_segments'] ?? [])), static fn ($line) => $line !== ''));
        $payload['hashtags'] = array_values(array_filter((array)($payload['hashtags'] ?? []), static fn ($tag) => trim((string)$tag) !== ''));

        if (count($payload['narration_segments']) < 6 || count($payload['narration_segments']) > 8) {
            throw new RuntimeException('내레이션 문단 구성에 문제가 있습니다. 대본을 다시 생성해주세요.');
        }
        $payload['narration'] = implode(' ', $payload['narration_segments']);
        $queries = array_values((array)($payload['stock_queries'] ?? []));
        if (count($queries) !== count($payload['narration_segments'])) {
            throw new RuntimeException('내레이션과 영상 검색어 개수가 일치하지 않습니다.');
        }
        foreach (array_merge($queries, [$payload['stock_fallback_query'] ?? '']) as $query) {
            if (!is_string($query) || trim($query) === '' || mb_strlen($query) > 100) {
                throw new RuntimeException('대본의 영상 검색어가 올바르지 않습니다.');
            }
        }
        $payload['stock_queries'] = $queries;
        $payload['caption_lines'] = $payload['narration_segments'];

        $payload['_usage'] = $response['usage'] ?? null;
        $payload['_cached_tokens'] = (int)($response['usage']['input_tokens_details']['cached_tokens'] ?? 0);

        return $payload;
    }

    public function synthesizeSpeech(string $text, string $targetPath, ?string $voice = null): void
    {
        $voice = $voice ?: $this->ttsVoice;
        if (!in_array($voice, self::VOICES, true)) {
            throw new RuntimeException('지원하지 않는 음성입니다. 스튜디오에서 보이스를 다시 선택하세요.');
        }
        if (strpos($this->ttsModel, 'gpt-realtime-') === 0) {
            $binary = (new RealtimeSpeechClient())->synthesize($this->apiKey, $this->baseUrl, $this->ttsModel, $voice, $text);
        } else {
            $binary = $this->requestBinary('/audio/speech', [
                'model' => $this->ttsModel,
                'voice' => $voice,
                'input' => $text,
                'response_format' => 'wav',
            ]);
        }

        if (file_put_contents($targetPath, $binary) === false) {
            throw new RuntimeException('음성 파일 저장에 실패했습니다.');
        }
    }

    public function synthesizeNarration(array $segments, string $targetPath, string $voice, array $options = []): array
    {
        if (strpos($this->ttsModel, 'gpt-realtime-') !== 0 || !in_array($voice, self::VOICES, true)) {
            throw new RuntimeException('문장별 음성 생성에는 Realtime 모델과 지원 보이스가 필요합니다. 음성 설정을 확인해주세요.');
        }
        $result = (new RealtimeSpeechClient())->synthesizeNarration($this->apiKey, $this->baseUrl, $this->ttsModel, $voice, $segments, $options);
        if (file_put_contents($targetPath, $result['audio']) === false) {
            throw new RuntimeException('음성 파일 저장에 실패했습니다.');
        }
        unset($result['audio']);
        return $result;
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
        ];
        // Newer GPT Image models do not use the legacy input_fidelity option.
        if (in_array($this->imageModel, ['gpt-image-1.5', 'gpt-image-1.5-2025-12-16'], true)) {
            $fields['input_fidelity'] = 'high';
        }
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
