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

        $google = GoogleSpeechClient::configuration();
        $provider = (string)($payload['tts_provider'] ?? $google['provider']);
        $voice = trim((string)($payload['voice'] ?? '')) ?: ($provider === 'google' ? $google['voice'] : OpenAIClient::modelConfiguration()['tts_voice']);
        $voices = $provider === 'google' ? array_keys(GoogleSpeechClient::VOICES) : OpenAIClient::VOICES;
        if (!in_array($provider, ['google', 'openai'], true) || !in_array($voice, $voices, true)) {
            throw new RuntimeException('지원하지 않는 음성입니다. 스튜디오에서 보이스를 다시 선택하세요.');
        }

        $bgmMode = (string)($payload['bgm_mode'] ?? 'default');
        $bgmVolume = max(0, min(30, (int)($payload['bgm_volume'] ?? 12))) / 100;
        $config = ShortsEnvironment::configuration();
        $music = $bgmMode === 'off' ? '' : ($bgmMode === 'upload' ? (string)($payload['bgm_path'] ?? '')
            : (is_readable($this->bgmPath) ? $this->bgmPath : $config['default_bgm_path']));
        if (!in_array($bgmMode, ['default', 'upload', 'off'], true) || ($music === '' && $bgmMode === 'upload')
            || ($music !== '' && !is_readable($music))) { throw new RuntimeException('배경음악 파일을 선택하고 읽기 권한을 확인하세요.'); }
        (new ShortsEnvironment())->assertReady($provider);
        if ($music !== '') { $this->probeAudioDuration($music); }
        if ($provider === 'google') { (new GoogleSpeechClient())->checkVoice($voice); }
        (new PixabayVideoClient())->assertAvailable(trim((string)($payload['stock_keyword'] ?? '')));

        $jobId = date('Ymd_His') . '_' . $this->slugify($keyword) . '_' . bin2hex(random_bytes(4));
        $jobDir = $this->outputRoot . '/' . $jobId;
        $this->ensureDirectory($jobDir);

        $script = $this->openAI->generateShortsScript([
            'keyword' => $keyword,
            'tone' => trim((string)($payload['tone'] ?? 'clickable')),
            'duration' => (int)($payload['duration'] ?? 30),
        ]);
        // Resolve and verify all footage before paying for narration. At most 9 searches / 8 clips per request.
        $footage = $this->prepareFootage($jobDir, $script, trim((string)($payload['stock_keyword'] ?? '')));
        $sceneImagePaths = array_column($footage, 'preview_path');
        $script['_media_source'] = 'pixabay';
        $script['_video_sources'] = array_map(static function (array $clip): array {
            return ['id' => $clip['id'], 'page_url' => $clip['page_url'], 'user' => $clip['user'], 'query' => $clip['query']];
        }, $footage);

        $coverPath = $jobDir . '/cover.png';
        $audioPath = $jobDir . '/voice.wav';
        $timedAudioPath = $jobDir . '/voice_timed.aac';
        $subtitlePath = $jobDir . '/captions.ass';
        $srtPath = $jobDir . '/captions.srt';
        $introClipPath = $jobDir . '/intro.mp4';
        $sceneVideoPath = $jobDir . '/scenes.mp4';
        $timelineListPath = $jobDir . '/timeline.txt';
        $timelineVideoPath = $jobDir . '/timeline.mp4';
        $videoPath = $jobDir . '/final.mp4';
        $manifestPath = $jobDir . '/manifest.json';

        $speech = $provider === 'google'
            ? (new GoogleSpeechClient())->synthesizeNarration($script['narration_segments'], $audioPath, $voice, (float)($payload['speech_speed'] ?? 1.0))
            : $this->openAI->synthesizeNarration($script['narration_segments'], $audioPath, $voice, [
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
        $script['_tts_provider'] = $provider;
        $script['_tts_voice'] = $voice;
        $script['_speech_style'] = $provider === 'google' ? 'voice_default' : ($payload['speech_style'] ?? 'natural');
        $script['_speech_speed'] = $payload['speech_speed'] ?? 1.0;
        $script['_bgm'] = ['enabled' => $music !== '' && $bgmVolume > 0, 'name' => basename($music), 'volume' => $bgmVolume];
        $script['_narration_url'] = $this->toPublicUrl($audioPath);
        (new ShortsThumbnailService($this->fontPath))->render($sceneImagePaths[0], $coverPath,
            trim((string)($script['cover_title'] ?? '')) ?: $keyword, (string)($script['cover_subtitle'] ?? ''));
        $this->renderStillClip($coverPath, $introClipPath, $this->introDuration);
        $sceneDurations = [];
        foreach ($speech['segments'] as $index => $segment) {
            $start = $index === 0 ? 0 : $segment['start'];
            $end = $speech['segments'][$index + 1]['start'] ?? $audioDuration;
            $sceneDurations[] = $end - $start;
        }
        $this->renderFootageVideo($footage, $sceneVideoPath, $sceneDurations);
        $this->concatVideoClips([$introClipPath, $sceneVideoPath], $timelineListPath, $timelineVideoPath);
        (new ShortsAudioService())->mix($this->ffmpegPath, $audioPath, $timedAudioPath, $audioDuration, $this->introDuration, $music, $bgmVolume);
        $finalAudioPath = $timedAudioPath;
        $this->renderVideo($timelineVideoPath, $finalAudioPath, $subtitlePath, $videoPath, $this->introDuration + $audioDuration);

        $manifest = [
            'keyword' => $keyword,
            'script' => $script,
            'caption_cues' => $cues,
            'scene_images' => array_map([$this, 'toPublicUrl'], $sceneImagePaths),
            'video_sources' => $script['_video_sources'],
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

    private function prepareFootage(string $jobDir, array $script, string $override): array
    {
        if (mb_strlen($override) > 100) { throw new RuntimeException('영상 검색어는 100자 이내로 입력하세요.'); }
        $client = new PixabayVideoClient();
        $queries = $override !== '' ? array_fill(0, count($script['narration_segments']), $override) : $script['stock_queries'];
        $fallback = $override !== '' ? $override : $script['stock_fallback_query'];
        $clips = [];
        $downloaded = [];
        $used = [];
        foreach ($queries as $index => $query) {
            $selectedQuery = $query;
            $video = $client->select($client->search($query), $used);
            if ($video === null && $query !== $fallback) {
                $selectedQuery = $fallback;
                $video = $client->select($client->search($fallback), $used);
            }
            if ($video === null) {
                throw new RuntimeException('사용 가능한 Pixabay 영상을 찾지 못했습니다: ' . $selectedQuery . '. 관련된 더 넓은 영상 검색어를 입력해 새 요청을 만드세요. 음성 생성은 실행하지 않았습니다.');
            }
            $id = $video['id'];
            if (!isset($downloaded[$id])) {
                $path = $jobDir . '/pixabay_' . $id . '.mp4';
                $client->download($video, $path);
                $video['path'] = $path;
                $video['media'] = $this->probeFootage($path);
                $video['preview_path'] = $jobDir . '/pixabay_' . $id . '.jpg';
                $command = escapeshellarg($this->ffmpegPath) . ' -hide_banner -loglevel error -y -ss '
                    . escapeshellarg((string)min(1, $video['media']['duration'] / 3)) . ' -i ' . escapeshellarg($path)
                    . ' -map 0:v:0 -frames:v 1 -q:v 2 ' . escapeshellarg($video['preview_path']) . ' 2>&1';
                $this->runMediaCommand($command, $video['preview_path'], '영상 미리보기 추출');
                $downloaded[$id] = $video;
            }
            $video = $downloaded[$id];
            $video['query'] = $selectedQuery;
            $clips[] = $video;
            $used[] = $id;
        }
        return $clips;
    }

    private function probeFootage(string $path): array
    {
        $output = [];
        exec(escapeshellarg($this->ffprobePath)
            . ' -v error -select_streams v:0 -show_entries stream=width,height:format=duration -of json '
            . escapeshellarg($path) . ' 2>&1', $output, $code);
        $data = json_decode(implode("\n", $output), true);
        $stream = $data['streams'][0] ?? [];
        $duration = (float)($data['format']['duration'] ?? 0);
        if ($code !== 0 || (int)($stream['width'] ?? 0) < 1 || (int)($stream['height'] ?? 0) < 1 || $duration <= 0) {
            throw new RuntimeException('다운로드한 Pixabay 영상을 읽을 수 없습니다. FFprobe 또는 영상 파일을 확인하세요.');
        }
        return ['width' => (int)$stream['width'], 'height' => (int)$stream['height'], 'duration' => $duration];
    }

    private function probeAudioDuration(string $path): float
    {
        exec(escapeshellarg($this->ffprobePath) . ' -v error -select_streams a:0 -show_entries stream=codec_type:format=duration -of json '
            . escapeshellarg($path) . ' 2>&1', $output, $code);
        $data = json_decode(implode("\n", $output), true);
        $duration = (float)($data['format']['duration'] ?? 0);
        if ($code !== 0 || ($data['streams'][0]['codec_type'] ?? '') !== 'audio' || $duration <= 0) {
            throw new RuntimeException('배경음악 파일에 재생 가능한 오디오가 없습니다. 다른 파일을 선택하세요.');
        }
        return $duration;
    }

    private function renderFootageVideo(array $clips, string $targetPath, array $durations): void
    {
        if ($clips === [] || count($clips) !== count($durations) || min($durations) <= 0) {
            throw new RuntimeException('영상 장면과 내레이션 길이가 일치하지 않습니다.');
        }
        $rendered = [];
        $elapsed = 0.0;
        $renderedFrames = 0;
        foreach ($clips as $index => $clip) {
            $elapsed += $durations[$index];
            $endFrame = (int)round($elapsed * 30);
            $frames = max(1, $endFrame - $renderedFrames);
            $renderedFrames += $frames;
            $path = dirname($targetPath) . sprintf('/footage_%02d.mp4', $index + 1);
            // Landscape footage retains its subject; the blurred duplicate fills the vertical canvas.
            $filter = $clip['media']['width'] / $clip['media']['height'] > 0.8
                ? '[0:v:0]split=2[bg][fg];[bg]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=24:2,setsar=1[back];[fg]scale=1080:1920:force_original_aspect_ratio=decrease,setsar=1[front];[back][front]overlay=(W-w)/2:(H-h)/2,fps=30,format=yuv420p,setsar=1,setpts=PTS-STARTPTS[out]'
                : '[0:v:0]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,fps=30,format=yuv420p,setsar=1,setpts=PTS-STARTPTS[out]';
            $command = escapeshellarg($this->ffmpegPath) . ' -hide_banner -loglevel error -y -stream_loop -1 -i ' . escapeshellarg($clip['path'])
                . ' -filter_complex_threads 1 -filter_complex ' . escapeshellarg($filter)
                . ' -map ' . escapeshellarg('[out]') . ' -an -sn -dn -frames:v ' . $frames
                . ' -c:v libx264 -preset veryfast -crf 20 -profile:v high -level 4.1 -video_track_timescale 15360 '
                . escapeshellarg($path) . ' 2>&1';
            $this->runMediaCommand($command, $path, 'Pixabay 장면 편집');
            $rendered[] = $path;
        }
        $this->concatVideoClips($rendered, dirname($targetPath) . '/footage-list.txt', $targetPath);
    }

    private function runMediaCommand(string $command, string $target, string $label): void
    {
        $output = [];
        exec($command, $output, $code);
        if ($code !== 0 || !is_file($target) || filesize($target) === 0) {
            throw new RuntimeException($label . " 실패.\n" . implode("\n", array_slice($output, -12)));
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
