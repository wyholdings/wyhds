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
    private float $introDuration = 0.6;
    private float $outroDuration = 0.8;

    public function __construct()
    {
        $config = ShortsEnvironment::configuration();
        $this->openAI = new OpenAIClient();
        $this->fontPath = $config['font_path'];
        $this->publicRoot = $config['public_root'];
        $configuredOutputRoot = trim((string)($_ENV['SHORTS_OUTPUT_ROOT'] ?? ''));
        $this->outputRoot = $this->normalizePath($config['output_root']);
        $this->outputRootLabel = $configuredOutputRoot !== '' ? $configuredOutputRoot : $this->outputRoot;
        $this->ffmpegPath = $config['ffmpeg_path'];
        $this->ffprobePath = $config['ffprobe_path'];
        $this->bgmPath = $config['bgm_path'];
    }

    public function generate(array $payload): array
    {
        $keyword = trim((string)($payload['keyword'] ?? ''));
        if ($keyword === '') {
            throw new RuntimeException('키워드를 입력해야 합니다.');
        }

        $voice = trim((string)($payload['voice'] ?? '')) ?: OpenAIClient::modelConfiguration()['tts_voice'];
        if (!in_array($voice, OpenAIClient::VOICES, true)) {
            throw new RuntimeException('지원하지 않는 음성입니다. 스튜디오에서 보이스를 다시 선택하세요.');
        }

        (new ShortsEnvironment())->assertReady();

        $jobId = date('Ymd_His') . '_' . $this->slugify($keyword) . '_' . bin2hex(random_bytes(4));
        $jobDir = $this->outputRoot . '/' . $jobId;
        $this->ensureDirectory($jobDir);

        $script = $this->openAI->generateShortsScript([
            'keyword' => $keyword,
            'tone' => trim((string)($payload['tone'] ?? 'clickable')),
            'duration' => (int)($payload['duration'] ?? 30),
        ]);
        $referenceImagePaths = isset($payload['reference_image_paths']) && is_array($payload['reference_image_paths'])
            ? array_values(array_filter($payload['reference_image_paths'], static fn ($path) => is_string($path) && $path !== ''))
            : [];

        $coverPath = $jobDir . '/cover.png';
        $outroPath = $jobDir . '/outro.png';
        $audioPath = $jobDir . '/voice.wav';
        $mixedAudioPath = $jobDir . '/voice_mix.aac';
        $timedAudioPath = $jobDir . '/voice_timed.aac';
        $subtitlePath = $jobDir . '/captions.ass';
        $srtPath = $jobDir . '/captions.srt';
        $introClipPath = $jobDir . '/intro.mp4';
        $sceneVideoPath = $jobDir . '/scenes.mp4';
        $outroClipPath = $jobDir . '/outro.mp4';
        $timelineListPath = $jobDir . '/timeline.txt';
        $timelineVideoPath = $jobDir . '/timeline.mp4';
        $videoPath = $jobDir . '/final.mp4';
        $manifestPath = $jobDir . '/manifest.json';

        $speech = $this->openAI->synthesizeNarration($script['narration_segments'], $audioPath, $voice, [
            'style' => $payload['speech_style'] ?? 'natural',
            'speed' => max(0.85, min(1.15, (float)($payload['speech_speed'] ?? 1.0))),
        ]);
        $audioDuration = $speech['duration'];
        $captionService = new ShortsCaptionService();
        $cues = $captionService->buildCues($speech['segments']);
        $captionService->writeAss($subtitlePath, $cues, $this->detectSubtitleFontName(), $this->introDuration);
        $captionService->writeSrt($srtPath, $cues, $this->introDuration);
        $script['caption_lines'] = array_column($cues, 'text');
        $script['_speech_segments'] = $speech['segments'];
        $script['_speech_style'] = $payload['speech_style'] ?? 'natural';
        $script['_speech_speed'] = $payload['speech_speed'] ?? 1.0;
        $this->createCoverImage($coverPath, $keyword, $script);
        $this->createOutroImage($outroPath);
        $sceneImagePaths = $this->createSceneImages($jobDir, $script, $keyword, $referenceImagePaths);
        $this->renderStillClip($coverPath, $introClipPath, $this->introDuration);
        $sceneDurations = [];
        foreach ($speech['segments'] as $index => $segment) {
            $start = $index === 0 ? 0 : $segment['start'];
            $end = $speech['segments'][$index + 1]['start'] ?? $audioDuration;
            $sceneDurations[] = $end - $start;
        }
        $this->renderSceneVideo($sceneImagePaths, $sceneVideoPath, $sceneDurations);
        $this->renderStillClip($outroPath, $outroClipPath, $this->outroDuration);
        $this->concatVideoClips([$introClipPath, $sceneVideoPath, $outroClipPath], $timelineListPath, $timelineVideoPath);
        $finalAudioPath = $this->prepareAudioTrack($audioPath, $mixedAudioPath, $timedAudioPath, $this->introDuration, $this->outroDuration);
        $this->renderVideo($timelineVideoPath, $finalAudioPath, $subtitlePath, $videoPath, $this->introDuration + $audioDuration + $this->outroDuration);

        $manifest = [
            'keyword' => $keyword,
            'script' => $script,
            'caption_cues' => $cues,
            'scene_images' => array_map([$this, 'toPublicUrl'], $sceneImagePaths),
            'cached_tokens' => $script['_cached_tokens'] ?? 0,
            'usage' => $script['_usage'] ?? null,
            'generated_at' => date('c'),
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'output_dir' => $jobDir,
            'cover_url' => $this->toPublicUrl($coverPath),
            'audio_url' => $this->toPublicUrl($finalAudioPath),
            'subtitle_url' => $this->toPublicUrl($srtPath),
            'video_url' => $this->toPublicUrl($videoPath),
            'scene_image_urls' => array_map([$this, 'toPublicUrl'], $sceneImagePaths),
            'script' => $script,
        ];
    }

    private function createCoverImage(string $targetPath, string $keyword, array $script): void
    {
        $bgPrompt = trim(implode("\n", [
            'Create a vertical 9:16 cinematic background image for a YouTube Shorts title card.',
            'Topic keyword: ' . $keyword,
            'Use one strong focal scene, premium editorial composition, dramatic lighting.',
            'No text, no letters, no numbers, no logo, no watermark, no UI.',
            'Leave a clean central area for large title overlay.',
        ]));

        $backgroundPath = preg_replace('/\.png$/', '_bg.png', $targetPath) ?: ($targetPath . '_bg.png');
        $this->openAI->generateImage($bgPrompt, $backgroundPath);
        $this->renderCoverTextOverlay($backgroundPath, $targetPath, trim((string)($script['cover_title'] ?? '')) ?: $keyword);
    }

    private function renderCoverTextOverlay(string $backgroundPath, string $targetPath, string $keyword): void
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD 확장이 필요합니다.');
        }

        if (!is_file($this->fontPath)) {
            throw new RuntimeException('SHORTS_FONT_PATH 또는 기본 폰트 경로를 찾을 수 없습니다: ' . $this->fontPath);
        }

        if (!is_file($backgroundPath)) {
            throw new RuntimeException('표지 배경 이미지가 없습니다: ' . $backgroundPath);
        }

        $imageData = file_get_contents($backgroundPath);
        $image = $imageData !== false ? @imagecreatefromstring($imageData) : false;
        if ($image === false) {
            throw new RuntimeException('표지 배경 이미지를 열지 못했습니다.');
        }

        $canvas = imagecreatetruecolor(1080, 1920);
        $scale = max(1080 / imagesx($image), 1920 / imagesy($image));
        $cropWidth = (int)round(1080 / $scale);
        $cropHeight = (int)round(1920 / $scale);
        imagecopyresampled($canvas, $image, 0, 0, (int)((imagesx($image) - $cropWidth) / 2), (int)((imagesy($image) - $cropHeight) / 2), 1080, 1920, $cropWidth, $cropHeight);
        imagedestroy($image);

        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);

        $overlay = imagecolorallocatealpha($canvas, 6, 10, 18, 45);
        imagefilledrectangle($canvas, 0, 0, 1080, 1920, $overlay);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 55);

        $lines = $this->wrapText(118, $this->fontPath, $keyword, 860);
        $lineHeight = 138;
        $startY = 760 - (int)(count($lines) * $lineHeight / 2);
        foreach ($lines as $index => $line) {
            $y = $startY + ($index * $lineHeight);
            imagettftext($canvas, 118, 0, 92, $y + 8, $shadow, $this->fontPath, $line);
            imagettftext($canvas, 118, 0, 84, $y, $white, $this->fontPath, $line);
        }

        $result = imagepng($canvas, $targetPath);
        imagedestroy($canvas);
        clearstatcache(true, $targetPath);

        if ($result === false || !is_file($targetPath) || filesize($targetPath) === 0) {
            throw new RuntimeException('커버 이미지 저장에 실패했습니다.');
        }
    }

    private function createOutroImage(string $targetPath): void
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
        $background = imagecolorallocate($image, 12, 18, 31);
        $accent = imagecolorallocate($image, 255, 196, 61);
        $white = imagecolorallocate($image, 255, 255, 255);

        imagefilledrectangle($image, 0, 0, $width, $height, $background);
        imagefilledrectangle($image, 90, 220, 990, 1640, imagecolorallocate($image, 20, 31, 52));
        $this->drawWrappedText($image, 78, 0, 120, 820, $white, $this->fontPath, '시청해주셔서', 840, 1.2);
        $this->drawWrappedText($image, 92, 0, 120, 980, $accent, $this->fontPath, '감사합니다.', 840, 1.2);

        $result = imagepng($image, $targetPath);
        imagedestroy($image);
        clearstatcache(true, $targetPath);

        if ($result === false || !is_file($targetPath) || filesize($targetPath) === 0) {
            throw new RuntimeException('아웃트로 이미지 저장에 실패했습니다.');
        }
    }

    private function renderVideo(string $sceneVideoPath, string $audioPath, string $subtitlePath, string $videoPath, float $totalDuration): void
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
            '%s -y -i %s -i %s -vf %s -c:v libx264 -preset medium -crf 19 -profile:v high -level 4.1 -movflags +faststart -c:a aac -b:a 192k -ar 48000 -pix_fmt yuv420p -t %s %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($sceneVideoPath),
            escapeshellarg($audioPath),
            escapeshellarg($videoFilter),
            escapeshellarg(number_format($totalDuration, 2, '.', '')),
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

    private function renderSceneVideo(array $sceneImagePaths, string $sceneVideoPath, array $durations): void
    {
        if ($sceneImagePaths === []) {
            throw new RuntimeException('장면 이미지가 없습니다.');
        }

        $sceneCount = count($sceneImagePaths);
        if (count($durations) !== $sceneCount || min($durations) <= 0) {
            throw new RuntimeException('장면 이미지와 발화 시간이 일치하지 않습니다.');
        }
        $transitionDuration = min(0.25, min($durations) / 3);
        $inputs = [];
        $filterParts = [];

        foreach ($sceneImagePaths as $index => $sceneImagePath) {
            $clipDuration = $durations[$index] + ($index < $sceneCount - 1 ? $transitionDuration : 0);
            $inputs[] = '-loop 1 -framerate 30 -t ' . escapeshellarg(number_format($clipDuration, 3, '.', '')) . ' -i ' . escapeshellarg($sceneImagePath);
            $frames = max(1, (int)round($clipDuration * 30));
            $zoomDirection = $index % 2 === 0 ? '1.02+0.04*on/' . $frames : '1.06-0.04*on/' . $frames;
            $filterParts[] = sprintf(
                '[%d:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,setsar=1,zoompan=z=\'%s\':x=\'iw/2-(iw/zoom/2)\':y=\'ih/2-(ih/zoom/2)\':d=1:s=1080x1920:fps=30,format=yuv420p,settb=AVTB,setpts=PTS-STARTPTS[v%d]',
                $index,
                $zoomDirection,
                $index
            );
        }

        $lastLabel = '[v0]';
        $currentOffset = $durations[0];
        for ($i = 1; $i < $sceneCount; $i++) {
            $nextLabel = sprintf('[v%d]', $i);
            $outLabel = sprintf('[vx%d]', $i);
            $transitionName = 'fade';
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
            $currentOffset += $durations[$i];
        }

        $filterComplex = implode(';', $filterParts);
        $command = sprintf(
            '%s -y %s -filter_complex_threads 1 -filter_complex %s -map %s -t %s -c:v libx264 -preset medium -crf 19 -pix_fmt yuv420p %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            implode(' ', $inputs),
            escapeshellarg($filterComplex),
            escapeshellarg($lastLabel),
            escapeshellarg(number_format(array_sum($durations), 3, '.', '')),
            escapeshellarg($sceneVideoPath)
        );

        exec($command, $output, $exitCode);
        if ($exitCode !== 0 || !is_file($sceneVideoPath)) {
            throw new RuntimeException("장면 영상 생성 실패.\n" . trim(implode("\n", $output)));
        }
    }

    private function renderStillClip(string $imagePath, string $clipPath, float $duration): void
    {
        $command = sprintf(
            '%s -y -loop 1 -t %s -i %s -vf %s -c:v libx264 -preset medium -crf 19 -pix_fmt yuv420p %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg(number_format($duration, 2, '.', '')),
            escapeshellarg($imagePath),
            escapeshellarg('scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,setsar=1,fps=30,format=yuv420p'),
            escapeshellarg($clipPath)
        );

        exec($command, $output, $exitCode);
        if ($exitCode !== 0 || !is_file($clipPath)) {
            throw new RuntimeException("인트로/아웃트로 클립 생성 실패.\n" . trim(implode("\n", $output)));
        }
    }

    private function concatVideoClips(array $clipPaths, string $listPath, string $outputPath): void
    {
        $body = '';
        foreach ($clipPaths as $clipPath) {
            $body .= "file '" . str_replace("'", "'\\''", str_replace('\\', '/', $clipPath)) . "'\n";
        }

        if (file_put_contents($listPath, $body) === false) {
            throw new RuntimeException('타임라인 목록 저장에 실패했습니다.');
        }

        $command = sprintf(
            '%s -y -f concat -safe 0 -i %s -c copy %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($listPath),
            escapeshellarg($outputPath)
        );

        exec($command, $output, $exitCode);
        if ($exitCode !== 0 || !is_file($outputPath)) {
            throw new RuntimeException("타임라인 결합 실패.\n" . trim(implode("\n", $output)));
        }
    }

    private function prepareAudioTrack(string $voicePath, string $mixedAudioPath, string $timedAudioPath, float $introDuration, float $outroDuration): string
    {
        if (!is_file($voicePath)) {
            throw new RuntimeException('보이스 파일이 없습니다: ' . $voicePath);
        }

        $baseAudioPath = $voicePath;
        if ($this->bgmPath !== '' && is_file($this->bgmPath)) {
            $command = sprintf(
                '%s -y -stream_loop -1 -i %s -i %s -filter_complex %s -map %s -c:a aac -b:a 192k -ar 48000 %s 2>&1',
                escapeshellarg($this->ffmpegPath),
                escapeshellarg($this->bgmPath),
                escapeshellarg($voicePath),
                escapeshellarg('[0:a]volume=0.06,atrim=0:3600,asetpts=PTS-STARTPTS[bgm];[1:a]asetpts=PTS-STARTPTS[voice];[bgm][voice]amix=inputs=2:duration=shortest:dropout_transition=2[aout]'),
                escapeshellarg('[aout]'),
                escapeshellarg($mixedAudioPath)
            );

            exec($command, $output, $exitCode);
            if ($exitCode !== 0 || !is_file($mixedAudioPath)) {
                throw new RuntimeException("배경음악 믹싱 실패.\n" . trim(implode("\n", $output)));
            }
            $baseAudioPath = $mixedAudioPath;
        }

        $delayMs = (int)round($introDuration * 1000);
        $padDuration = number_format($outroDuration, 2, '.', '');
        $command = sprintf(
            '%s -y -i %s -af %s -c:a aac -b:a 192k -ar 48000 %s 2>&1',
            escapeshellarg($this->ffmpegPath),
            escapeshellarg($baseAudioPath),
            escapeshellarg("loudnorm=I=-16:TP=-1.5:LRA=11,adelay={$delayMs}:all=1,apad=pad_dur={$padDuration}"),
            escapeshellarg($timedAudioPath)
        );

        exec($command, $output, $exitCode);
        if ($exitCode !== 0 || !is_file($timedAudioPath)) {
            throw new RuntimeException("오디오 타이밍 보정 실패.\n" . trim(implode("\n", $output)));
        }

        return $timedAudioPath;
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

        if (str_contains($normalized, 'nanumgothic')) {
            return 'NanumGothic';
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
