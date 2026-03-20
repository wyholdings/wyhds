<?php

namespace App\Services;

use RuntimeException;

class ShortsAutomationService
{
    private OpenAIClient $openAI;
    private string $fontPath;
    private string $publicRoot;
    private string $outputRoot;
    private string $outputRootLabel;
    private string $ffmpegPath;
    private string $ffprobePath;
    private string $bgmPath;

    public function __construct()
    {
        $this->openAI = new OpenAIClient();
        $this->fontPath = (string)($_ENV['SHORTS_FONT_PATH'] ?? 'C:/Windows/Fonts/malgun.ttf');
        $this->publicRoot = realpath(__DIR__ . '/../../public') ?: (__DIR__ . '/../../public');
        $configuredOutputRoot = trim((string)($_ENV['SHORTS_OUTPUT_ROOT'] ?? ''));
        $this->outputRoot = $configuredOutputRoot !== ''
            ? $this->normalizePath($configuredOutputRoot)
            : $this->publicRoot . '/generated/shorts';
        $this->outputRootLabel = $configuredOutputRoot !== '' ? $configuredOutputRoot : $this->outputRoot;
        $this->ffmpegPath = trim((string)($_ENV['FFMPEG_PATH'] ?? 'ffmpeg'));
        $this->ffprobePath = trim((string)($_ENV['FFPROBE_PATH'] ?? 'ffprobe'));
        $this->bgmPath = trim((string)($_ENV['SHORTS_BGM_PATH'] ?? ''));
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
        $referenceImagePaths = isset($payload['reference_image_paths']) && is_array($payload['reference_image_paths'])
            ? array_values(array_filter($payload['reference_image_paths'], static fn ($path) => is_string($path) && $path !== ''))
            : [];

        $coverPath = $jobDir . '/cover.png';
        $audioPath = $jobDir . '/voice.aac';
        $mixedAudioPath = $jobDir . '/voice_mix.aac';
        $subtitlePath = $jobDir . '/captions.ass';
        $sceneVideoPath = $jobDir . '/scenes.mp4';
        $videoPath = $jobDir . '/final.mp4';
        $manifestPath = $jobDir . '/manifest.json';

        $this->createCoverImage($coverPath, $keyword, $script);
        if (!is_file($coverPath)) {
            throw new RuntimeException('커버 이미지 생성 후 파일을 찾을 수 없습니다: ' . $coverPath);
        }

        $this->openAI->synthesizeSpeech($script['narration'], $audioPath, $payload['voice'] ?? null);
        if (!is_file($audioPath)) {
            throw new RuntimeException('음성 생성 후 파일을 찾을 수 없습니다: ' . $audioPath);
        }

        $duration = $this->probeDuration($audioPath) ?? max(30, (int)($payload['duration'] ?? 60));
        $this->createAssSubtitleFile(
            $subtitlePath,
            (array)($script['caption_lines'] ?? []),
            (array)($script['caption_highlights'] ?? []),
            $duration
        );
        $sceneImagePaths = $this->createSceneImages($jobDir, $script, $keyword, $referenceImagePaths);
        $this->renderSceneVideo($sceneImagePaths, $sceneVideoPath, $duration);
        $finalAudioPath = $this->prepareAudioTrack($audioPath, $mixedAudioPath);
        $this->renderVideo($sceneVideoPath, $finalAudioPath, $subtitlePath, $videoPath);

        $manifest = [
            'keyword' => $keyword,
            'script' => $script,
            'scene_images' => array_map([$this, 'toPublicUrl'], $sceneImagePaths),
            'generated_at' => date('c'),
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'output_dir' => $jobDir,
            'cover_url' => $this->toPublicUrl($coverPath),
            'audio_url' => $this->toPublicUrl($finalAudioPath),
            'video_url' => $this->toPublicUrl($videoPath),
            'scene_image_urls' => array_map([$this, 'toPublicUrl'], $sceneImagePaths),
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

        $this->drawWrappedText($image, 96, 0, 90, 300, $white, $this->fontPath, (string)($script['cover_title'] ?? $keyword), 820, 1.2);
        $this->drawWrappedText($image, 52, 0, 95, 530, $muted, $this->fontPath, (string)($script['cover_subtitle'] ?? $keyword), 820, 1.4);
        $this->drawWrappedText($image, 38, 0, 110, 1260, $accent, $this->fontPath, strtoupper((string)($keyword)), 820, 1.4);

        $captionPreview = array_slice((array)($script['caption_lines'] ?? []), 0, 4);
        $this->drawWrappedText($image, 46, 0, 110, 1350, $white, $this->fontPath, implode("\n", $captionPreview), 780, 1.55);

        $result = imagepng($image, $targetPath);
        imagedestroy($image);

        clearstatcache(true, $targetPath);

        if ($result === false || !is_file($targetPath) || filesize($targetPath) === 0) {
            throw new RuntimeException('커버 이미지 저장에 실패했습니다.');
        }
    }

    private function renderVideo(string $sceneVideoPath, string $audioPath, string $subtitlePath, string $videoPath): void
    {
        if (!is_file($sceneVideoPath)) {
            throw new RuntimeException('ffmpeg 입력용 장면 영상이 없습니다: ' . $sceneVideoPath);
        }

        if (!is_file($audioPath)) {
            throw new RuntimeException('ffmpeg 입력용 음성 파일이 없습니다: ' . $audioPath);
        }

        if (!is_file($subtitlePath)) {
            throw new RuntimeException('ffmpeg 입력용 자막 파일이 없습니다: ' . $subtitlePath);
        }

        $subtitleFilterPath = str_replace('\\', '/', $subtitlePath);
        $subtitleFilterPath = str_replace(':', '\:', $subtitleFilterPath);
        $videoFilter = "ass='{$subtitleFilterPath}',eq=contrast=1.04:saturation=1.08:brightness=0.01,format=yuv420p";
        $command = sprintf(
            '%s -y -i %s -i %s -vf %s -c:v libx264 -preset medium -crf 19 -profile:v high -level 4.1 -movflags +faststart -c:a aac -b:a 192k -ar 48000 -pix_fmt yuv420p -shortest %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($sceneVideoPath),
            escapeshellarg($audioPath),
            escapeshellarg($videoFilter),
            escapeshellarg($videoPath)
        );

        exec($command, $output, $exitCode);
        if ($exitCode !== 0 || !is_file($videoPath)) {
            throw new RuntimeException("ffmpeg 실행 실패. FFMPEG_PATH를 확인하세요.\n" . trim(implode("\n", $output)));
        }
    }

    private function createSceneImages(string $jobDir, array $script, string $keyword, array $referenceImagePaths): array
    {
        $scenePrompts = array_values((array)($script['scene_prompts'] ?? []));
        if ($scenePrompts === []) {
            throw new RuntimeException('장면 이미지 프롬프트가 없습니다.');
        }

        $visualStyle = trim((string)($script['visual_style'] ?? ''));
        $visualAnchor = trim((string)($script['visual_anchor'] ?? ''));
        $cameraLanguage = trim((string)($script['camera_language'] ?? ''));
        $lightingStyle = trim((string)($script['lighting_style'] ?? ''));
        $colorPalette = trim((string)($script['color_palette'] ?? ''));
        $paths = [];
        foreach ($scenePrompts as $index => $scenePrompt) {
            $imagePath = sprintf('%s/scene_%02d.png', $jobDir, $index + 1);
            $prompt = $this->buildSceneImagePrompt(
                $keyword,
                (string)$scenePrompt,
                $visualStyle,
                $visualAnchor,
                $cameraLanguage,
                $lightingStyle,
                $colorPalette,
                $index
            );
            if ($referenceImagePaths !== []) {
                $this->openAI->generateImageFromReference($prompt, $referenceImagePaths, $imagePath);
            } else {
                $this->openAI->generateImage($prompt, $imagePath);
            }

            if (!is_file($imagePath) || filesize($imagePath) === 0) {
                throw new RuntimeException('장면 이미지 생성에 실패했습니다: ' . $imagePath);
            }

            $paths[] = $imagePath;
        }

        return $paths;
    }

    private function buildSceneImagePrompt(
        string $keyword,
        string $scenePrompt,
        string $visualStyle,
        string $visualAnchor,
        string $cameraLanguage,
        string $lightingStyle,
        string $colorPalette,
        int $sceneIndex
    ): string
    {
        $style = $visualStyle !== '' ? $visualStyle : 'clean, realistic, high contrast, premium documentary still';
        $anchor = $visualAnchor !== '' ? $visualAnchor : 'same visual identity, same subject feeling, same lens mood across all scenes';
        $camera = $cameraLanguage !== '' ? $cameraLanguage : $this->defaultCameraLanguage($sceneIndex);
        $lighting = $lightingStyle !== '' ? $lightingStyle : 'cinematic soft key light, subtle rim light, controlled contrast';
        $palette = $colorPalette !== '' ? $colorPalette : 'muted navy, warm gold, controlled highlights';

        return trim(implode("\n", [
            'Create a vertical 9:16 cinematic still image for a YouTube Shorts video.',
            'Topic keyword: ' . $keyword,
            'Shared visual anchor: ' . $anchor,
            'Scene: ' . $scenePrompt,
            'Visual style: ' . $style,
            'Camera language: ' . $camera,
            'Lighting style: ' . $lighting,
            'Color palette: ' . $palette,
            'Requirements: keep the same subject identity and same visual world as previous scenes.',
            'Compose like a premium editorial frame, with one clear focal point and cinematic depth.',
            'No text, no letters, no numbers, no watermark, no logo, no UI, no collage, no split screen.',
            'Strong focal subject, clear depth, dramatic lighting, social-video friendly composition.',
        ]));
    }

    private function defaultCameraLanguage(int $sceneIndex): string
    {
        $patterns = [
            'close-up portrait framing, 50mm lens feel, shallow depth of field',
            'medium shot, 35mm documentary lens feel, layered background depth',
            'detail close-up, macro-like emphasis, crisp subject separation',
            'hero shot, slightly low angle, cinematic subject dominance',
        ];

        return $patterns[$sceneIndex % count($patterns)];
    }

    private function renderSceneVideo(array $sceneImagePaths, string $sceneVideoPath, float $duration): void
    {
        if ($sceneImagePaths === []) {
            throw new RuntimeException('장면 이미지가 없습니다.');
        }

        $sceneCount = count($sceneImagePaths);
        $transitionDuration = 0.45;
        $sceneDuration = max(4.5, $duration / $sceneCount);
        $clipDuration = $sceneDuration + $transitionDuration;
        $inputs = [];
        $filterParts = [];

        foreach ($sceneImagePaths as $index => $sceneImagePath) {
            $inputs[] = '-loop 1 -t ' . escapeshellarg(number_format($clipDuration, 2, '.', '')) . ' -i ' . escapeshellarg($sceneImagePath);
            $zoomDirection = $index % 2 === 0 ? "min(zoom+0.0011,1.14)" : "if(lte(zoom,1.0),1.12,max(1.0,zoom-0.0007))";
            $filterParts[] = sprintf(
                '[%d:v]scale=1080:1920,zoompan=z=\'%s\':x=\'iw/2-(iw/zoom/2)\':y=\'ih/2-(ih/zoom/2)\':d=1:s=1080x1920:fps=30,eq=contrast=1.05:saturation=1.1:brightness=0.01,format=yuv420p,setpts=PTS-STARTPTS[v%d]',
                $index,
                $zoomDirection,
                $index
            );
        }

        $lastLabel = '[v0]';
        $currentOffset = $sceneDuration;
        for ($i = 1; $i < $sceneCount; $i++) {
            $nextLabel = sprintf('[v%d]', $i);
            $outLabel = sprintf('[vx%d]', $i);
            $transitionName = $i % 2 === 0 ? 'fade' : 'smoothleft';
            $filterParts[] = sprintf(
                '%s%sxfade=transition=%s:duration=%.2f:offset=%.2f%s',
                $lastLabel,
                $nextLabel,
                $transitionName,
                $transitionDuration,
                $currentOffset,
                $outLabel
            );
            $lastLabel = $outLabel;
            $currentOffset += $sceneDuration - $transitionDuration;
        }

        $filterComplex = implode(';', $filterParts);
        $command = sprintf(
            '%s -y %s -filter_complex %s -map %s -c:v libx264 -preset medium -crf 19 -pix_fmt yuv420p %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            implode(' ', $inputs),
            escapeshellarg($filterComplex),
            escapeshellarg($lastLabel),
            escapeshellarg($sceneVideoPath)
        );

        exec($command, $output, $exitCode);
        if ($exitCode !== 0 || !is_file($sceneVideoPath)) {
            throw new RuntimeException("장면 영상 생성 실패.\n" . trim(implode("\n", $output)));
        }
    }

    private function prepareAudioTrack(string $voicePath, string $mixedAudioPath): string
    {
        if (!is_file($voicePath)) {
            throw new RuntimeException('보이스 파일이 없습니다: ' . $voicePath);
        }

        if ($this->bgmPath === '' || !is_file($this->bgmPath)) {
            return $voicePath;
        }

        $command = sprintf(
            '%s -y -stream_loop -1 -i %s -i %s -filter_complex %s -map %s -c:a aac -b:a 192k -ar 48000 %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($this->bgmPath),
            escapeshellarg($voicePath),
            escapeshellarg('[0:a]volume=0.08,atrim=0:3600,asetpts=PTS-STARTPTS[bgm];[1:a]loudnorm=I=-16:TP=-1.5:LRA=11,asetpts=PTS-STARTPTS[voice];[bgm][voice]amix=inputs=2:duration=shortest:dropout_transition=2[aout]'),
            escapeshellarg('[aout]'),
            escapeshellarg($mixedAudioPath)
        );

        exec($command, $output, $exitCode);
        if ($exitCode !== 0 || !is_file($mixedAudioPath)) {
            throw new RuntimeException("배경음악 믹싱 실패.\n" . trim(implode("\n", $output)));
        }

        return $mixedAudioPath;
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

    private function createAssSubtitleFile(string $targetPath, array $captionLines, array $captionHighlights, float $duration): void
    {
        $captionLines = array_values(array_filter(array_map(
            static fn ($line) => trim((string)$line),
            $captionLines
        ), static fn ($line) => $line !== ''));

        $captionHighlights = array_values(array_map(
            static fn ($line) => trim((string)$line),
            $captionHighlights
        ));

        if ($captionLines === []) {
            $captionLines = [''];
        }

        $fontName = $this->detectSubtitleFontName();
        $body = "[Script Info]\n";
        $body .= "ScriptType: v4.00+\n";
        $body .= "PlayResX: 1080\n";
        $body .= "PlayResY: 1920\n";
        $body .= "ScaledBorderAndShadow: yes\n\n";
        $body .= "[V4+ Styles]\n";
        $body .= "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        $body .= "Style: Main,{$fontName},58,&H00FFFFFF,&H000000FF,&H00121823,&H28000000,-1,0,0,0,100,100,0,0,1,2.8,0,2,70,70,150,1\n\n";
        $body .= "[Events]\n";
        $body .= "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

        $chunkCount = count($captionLines);
        $segmentDuration = max(1.2, $duration / max(1, $chunkCount));
        $cursor = 0.0;

        foreach ($captionLines as $index => $line) {
            $start = $cursor;
            $end = min($duration, $index === $chunkCount - 1 ? $duration : $cursor + $segmentDuration);
            $highlight = $captionHighlights[$index] ?? '';
            $body .= 'Dialogue: 0,' . $this->formatAssTime($start) . ',' . $this->formatAssTime($end) . ',Main,,0,0,0,,' . $this->formatAssText($line, $highlight, max(0.8, $end - $start)) . "\n";
            $cursor = $end;
        }

        if (file_put_contents($targetPath, $body) === false) {
            throw new RuntimeException('ASS 자막 파일 저장에 실패했습니다: ' . $targetPath);
        }
    }

    private function formatAssTime(float $seconds): string
    {
        $centiseconds = (int)round($seconds * 100);
        $hours = (int)floor($centiseconds / 360000);
        $centiseconds -= $hours * 360000;
        $minutes = (int)floor($centiseconds / 6000);
        $centiseconds -= $minutes * 6000;
        $secs = (int)floor($centiseconds / 100);
        $centiseconds -= $secs * 100;

        return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $secs, $centiseconds);
    }

    private function formatAssText(string $line, string $highlight, float $segmentDuration): string
    {
        $cleanLine = str_replace(["\r", "\n"], ' ', trim($line));
        $highlight = $this->escapeAss(trim($highlight));

        $animatedText = $this->buildKaraokeText($cleanLine, $highlight, $segmentDuration);

        if ($animatedText === '') {
            $animatedText = $this->escapeAss($cleanLine);
        }

        return '{\an2\pos(540,1635)\fad(80,120)}' . $animatedText;
    }

    private function buildKaraokeText(string $line, string $highlight, float $segmentDuration): string
    {
        $tokens = $this->tokenizeCaption($line);
        if ($tokens === []) {
            return '';
        }

        $perTokenCentiseconds = max(12, (int)floor(($segmentDuration * 100) / count($tokens)));
        $parts = [];

        foreach ($tokens as $token) {
            if (trim($token) === '') {
                $parts[] = $token;
                continue;
            }

            $escapedToken = $this->escapeAss($token);
            if ($highlight !== '' && mb_strpos($token, $highlight) !== false) {
                $escapedToken = '{\fs72\c&H0030D7FF&\bord3}' . $escapedToken . '{\rMain}';
            }

            $parts[] = '{\k' . $perTokenCentiseconds . '}' . $escapedToken;
        }

        return implode('', $parts);
    }

    private function tokenizeCaption(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return [];
        }

        $tokens = preg_split('/(\s+)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (is_array($tokens) && count(array_filter($tokens, static fn ($token) => trim($token) !== '')) >= 2) {
            return $tokens;
        }

        preg_match_all('/./us', $line, $matches);
        return $matches[0] ?? [];
    }

    private function escapeAss(string $text): string
    {
        return str_replace(['{', '}'], ['\{', '\}'], $text);
    }

    private function probeDuration(string $path): ?float
    {
        $command = sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
            escapeshellarg($this->ffprobePath),
            escapeshellarg($path)
        );

        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            return null;
        }

        $value = trim(implode("\n", $output));
        return is_numeric($value) ? (float)$value : null;
    }

    private function detectSubtitleFontName(): string
    {
        $normalized = strtolower(str_replace('\\', '/', $this->fontPath));

        if (str_contains($normalized, 'malgun')) {
            return 'Malgun Gothic';
        }

        if (str_contains($normalized, 'dejavusans')) {
            return 'DejaVu Sans';
        }

        if (str_contains($normalized, 'arial')) {
            return 'Arial';
        }

        return 'sans-serif';
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            if (!is_writable($path)) {
                throw new RuntimeException('출력 폴더에 쓰기 권한이 없습니다: ' . $path);
            }
            return;
        }

        $parent = dirname($path);
        if (!is_dir($parent)) {
            $this->ensureDirectory($parent);
        }

        if (!is_writable($parent)) {
            throw new RuntimeException('상위 폴더에 쓰기 권한이 없습니다: ' . $parent);
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException('출력 폴더 생성에 실패했습니다: ' . $path . ' (output root: ' . $this->outputRootLabel . ')');
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

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function slugify(string $text): string
    {
        $text = trim($text);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($ascii) && trim($ascii) !== '') {
            $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii);
        } else {
            $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
        }

        $slug = trim((string)$slug, '-');

        if ($slug === '') {
            $slug = 'shorts';
        }

        return mb_strtolower($slug, 'UTF-8');
    }
}
