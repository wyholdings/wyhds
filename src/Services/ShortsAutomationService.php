<?php

namespace App\Services;

use RuntimeException;

class ShortsAutomationService
{
    private OpenAIClient $openAI;
    private string $fontPath;
    private string $publicRoot;
    private string $outputRoot;
    private string $ffmpegPath;

    public function __construct()
    {
        $this->openAI = new OpenAIClient();
        $this->fontPath = (string)($_ENV['SHORTS_FONT_PATH'] ?? 'C:/Windows/Fonts/malgun.ttf');
        $this->publicRoot = realpath(__DIR__ . '/../../public') ?: (__DIR__ . '/../../public');
        $this->outputRoot = $this->publicRoot . '/generated/shorts';
        $this->ffmpegPath = trim((string)($_ENV['FFMPEG_PATH'] ?? 'ffmpeg'));
    }

    public function generate(array $payload): array
    {
        $keyword = trim((string)($payload['keyword'] ?? ''));
        if ($keyword === '') {
            throw new RuntimeException('키워드를 입력해야 합니다.');
        }

        $jobId = date('Ymd_His') . '_' . $this->slugify($keyword);
        $jobDir = $this->outputRoot . '/' . $jobId;
        $this->ensureDirectory($jobDir);

        $script = $this->openAI->generateShortsScript([
            'keyword' => $keyword,
            'tone' => trim((string)($payload['tone'] ?? 'clickable')),
            'duration' => (int)($payload['duration'] ?? 30),
            'project' => $payload['project'] ?? null,
        ]);

        $coverPath = $jobDir . '/cover.png';
        $audioPath = $jobDir . '/voice.aac';
        $videoPath = $jobDir . '/final.mp4';
        $manifestPath = $jobDir . '/manifest.json';

        $this->createCoverImage($coverPath, $keyword, $script);
        $this->openAI->synthesizeSpeech($script['narration'], $audioPath, $payload['voice'] ?? null);
        $this->renderVideo($coverPath, $audioPath, $videoPath);

        $manifest = [
            'keyword' => $keyword,
            'script' => $script,
            'generated_at' => date('c'),
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'output_dir' => $jobDir,
            'cover_url' => $this->toPublicUrl($coverPath),
            'audio_url' => $this->toPublicUrl($audioPath),
            'video_url' => $this->toPublicUrl($videoPath),
            'script' => $script,
        ];
    }

    private function createCoverImage(string $targetPath, string $keyword, array $script): void
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD 확장이 필요합니다.');
        }

        if (!is_file($this->fontPath)) {
            throw new RuntimeException('SHORTS_FONT_PATH 또는 기본 폰트 경로를 찾을 수 없습니다: ' . $this->fontPath);
        }

        $width = 1080;
        $height = 1920;
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('커버 이미지 생성에 실패했습니다.');
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        $top = imagecolorallocate($image, 14, 25, 43);
        $bottom = imagecolorallocate($image, 39, 84, 138);
        $accent = imagecolorallocate($image, 255, 196, 61);
        $white = imagecolorallocate($image, 255, 255, 255);
        $muted = imagecolorallocate($image, 210, 223, 241);

        for ($y = 0; $y < $height; $y++) {
            $ratio = $height > 1 ? $y / ($height - 1) : 0;
            $r = (int)(14 + ((39 - 14) * $ratio));
            $g = (int)(25 + ((84 - 25) * $ratio));
            $b = (int)(43 + ((138 - 43) * $ratio));
            $lineColor = imagecolorallocate($image, $r, $g, $b);
            imageline($image, 0, $y, $width, $y, $lineColor);
        }

        imagefilledellipse($image, 870, 300, 540, 540, $accent);
        imagefilledrectangle($image, 80, 1180, 1000, 1550, imagecolorallocatealpha($image, 10, 18, 33, 35));

        $this->drawWrappedText($image, 58, 0, 90, 180, $muted, $this->fontPath, 'AUTO SHORTS');
        $this->drawWrappedText($image, 92, 0, 90, 340, $white, $this->fontPath, (string)($script['cover_title'] ?? $keyword), 820, 1.2);
        $this->drawWrappedText($image, 50, 0, 95, 560, $muted, $this->fontPath, (string)($script['cover_subtitle'] ?? $keyword), 820, 1.4);
        $this->drawWrappedText($image, 38, 0, 110, 1260, $accent, $this->fontPath, strtoupper((string)($keyword)), 820, 1.4);

        $captionPreview = array_slice((array)($script['caption_lines'] ?? []), 0, 4);
        $this->drawWrappedText($image, 46, 0, 110, 1350, $white, $this->fontPath, implode("\n", $captionPreview), 780, 1.55);

        $result = imagepng($image, $targetPath);
        imagedestroy($image);

        if ($result === false) {
            throw new RuntimeException('커버 이미지 저장에 실패했습니다.');
        }
    }

    private function renderVideo(string $coverPath, string $audioPath, string $videoPath): void
    {
        $command = sprintf(
            '%s -y -loop 1 -framerate 30 -i %s -i %s -vf %s -c:v libx264 -tune stillimage -c:a aac -b:a 192k -pix_fmt yuv420p -shortest %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($coverPath),
            escapeshellarg($audioPath),
            escapeshellarg('scale=1080:1920,format=yuv420p'),
            escapeshellarg($videoPath)
        );

        exec($command, $output, $exitCode);
        if ($exitCode !== 0 || !is_file($videoPath)) {
            throw new RuntimeException("ffmpeg 실행 실패. FFMPEG_PATH를 확인하세요.\n" . trim(implode("\n", $output)));
        }
    }

    private function drawWrappedText($image, int $fontSize, float $angle, int $x, int $y, int $color, string $fontPath, string $text, int $maxWidth = 900, float $lineHeight = 1.35): void
    {
        $lines = $this->wrapText($fontSize, $fontPath, $text, $maxWidth);
        $offsetY = 0;

        foreach ($lines as $line) {
            imagettftext($image, $fontSize, $angle, $x, $y + $offsetY, $color, $fontPath, $line);
            $offsetY += (int)($fontSize * $lineHeight);
        }
    }

    private function wrapText(int $fontSize, string $fontPath, string $text, int $maxWidth): array
    {
        $text = str_replace("\r", '', trim($text));
        $paragraphs = explode("\n", $text);
        $lines = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                $lines[] = '';
                continue;
            }

            $current = '';
            foreach ($this->splitUtf8($paragraph) as $char) {
                $candidate = $current . $char;
                $box = imagettfbbox($fontSize, 0, $fontPath, $candidate);
                $lineWidth = $box ? abs($box[2] - $box[0]) : 0;

                if ($current !== '' && $lineWidth > $maxWidth) {
                    $lines[] = $current;
                    $current = $char;
                    continue;
                }

                $current = $candidate;
            }

            if ($current !== '') {
                $lines[] = $current;
            }
        }

        return $lines === [] ? [''] : $lines;
    }

    private function splitUtf8(string $text): array
    {
        preg_match_all('/./us', $text, $matches);
        return $matches[0] ?? [];
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException('출력 폴더 생성에 실패했습니다: ' . $path);
        }
    }

    private function toPublicUrl(string $filePath): string
    {
        $normalized = str_replace('\\', '/', $filePath);
        $publicRoot = str_replace('\\', '/', $this->publicRoot);

        if (strpos($normalized, $publicRoot) !== 0) {
            throw new RuntimeException('공개 URL 변환에 실패했습니다.');
        }

        return substr($normalized, strlen($publicRoot));
    }

    private function slugify(string $text): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9가-힣]+/u', '-', trim($text));
        $slug = trim((string)$slug, '-');

        if ($slug === '') {
            $slug = 'shorts';
        }

        return mb_strtolower($slug, 'UTF-8');
    }
}
