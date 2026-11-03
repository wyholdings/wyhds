<?php

namespace App\Services;

use RuntimeException;

class ShortsEnvironment
{
    public static function configuration(): array
    {
        $publicRoot = realpath(__DIR__ . '/../../public') ?: __DIR__ . '/../../public';
        $font = PHP_OS_FAMILY === 'Windows'
            ? 'C:/Windows/Fonts/malgun.ttf'
            : '/usr/share/fonts/truetype/nanum/NanumGothic.ttf';

        return [
            'public_root' => $publicRoot,
            'output_root' => trim((string)($_ENV['SHORTS_OUTPUT_ROOT'] ?? '')) ?: $publicRoot . '/generated/shorts',
            'font_path' => trim((string)($_ENV['SHORTS_FONT_PATH'] ?? '')) ?: $font,
            'ffmpeg_path' => trim((string)($_ENV['FFMPEG_PATH'] ?? '')) ?: 'ffmpeg',
            'ffprobe_path' => trim((string)($_ENV['FFPROBE_PATH'] ?? '')) ?: 'ffprobe',
            'bgm_path' => trim((string)($_ENV['SHORTS_BGM_PATH'] ?? '')) ?: $publicRoot . '/assets/bgm/shorts-ambient.m4a',
            'default_bgm_path' => $publicRoot . '/assets/bgm/shorts-ambient.m4a',
            'pixabay_cache_root' => trim((string)($_ENV['PIXABAY_CACHE_ROOT'] ?? '')) ?: __DIR__ . '/../../var/cache/shorts/pixabay',
        ];
    }

    /** Local checks only: no API calls, directory creation, or database writes. */
    public function inspect(string $provider = ''): array
    {
        $config = self::configuration();
        $checks = [];
        $add = static function (string $name, bool $ok, string $message) use (&$checks): void {
            $checks[] = ['name' => $name, 'status' => $ok ? 'ok' : 'error', 'message' => $message];
        };

        $add('API key', trim((string)($_ENV['OPENAI_API_KEY'] ?? '')) !== '', 'OPENAI_API_KEY 설정 여부 (인증·잔액은 별도 확인)');
        $models = OpenAIClient::modelConfiguration();
        $add('Text model', true, $models['text_model'] . ' (실제 API 사용 가능 여부는 별도 확인)');
        $add('Pixabay key', trim((string)($_ENV['PIXABAY_API_KEY'] ?? '')) !== '', 'PIXABAY_API_KEY 설정 필요 (인증은 실제 검색 시 확인)');
        $cacheAncestor = $config['pixabay_cache_root'];
        while (!file_exists($cacheAncestor) && dirname($cacheAncestor) !== $cacheAncestor) {
            $cacheAncestor = dirname($cacheAncestor);
        }
        $add('Pixabay cache', is_dir($cacheAncestor) && is_writable($cacheAncestor), '24시간 검색 캐시 폴더 쓰기 권한 필요: ' . $config['pixabay_cache_root']);
        $google = GoogleSpeechClient::configuration();
        $provider = $provider !== '' ? $provider : $google['provider'];
        $add('Speech provider', in_array($provider, ['google', 'openai'], true), $provider . ' (SHORTS_TTS_PROVIDER)');
        if ($provider === 'google') {
            $add('Google TTS key', trim((string)($_ENV['GOOGLE_TTS_API_KEY'] ?? '')) !== '', 'GOOGLE_TTS_API_KEY 설정 필요 (Cloud Text-to-Speech API, 결제 연결 필요)');
            $add('Google voice', isset(GoogleSpeechClient::VOICES[$google['voice']]), $google['voice'] . ' / ko-KR');
        } elseif (strpos($models['tts_model'], 'gpt-realtime-') === 0) {
            $add('Speech model', true, $models['tts_model'] . ' / ' . $models['tts_voice']);
            $add('PHP openssl', extension_loaded('openssl'), 'Realtime 음성 연결을 위한 TLS 지원 필요');
            $add('Realtime transport', is_callable('stream_socket_client'), 'Realtime WebSocket 연결을 위한 stream_socket_client 허용 필요');
            $add('Speech voice', in_array($models['tts_voice'], OpenAIClient::VOICES, true), 'Realtime 지원 보이스를 선택하세요: ' . implode(', ', OpenAIClient::VOICES));
        } else {
            $add('Speech timing', false, '문단별 음성·자막 동기화를 위해 OPENAI_TTS_MODEL=' . OpenAIClient::DEFAULT_TTS_MODEL . ' 로 설정하세요.');
        }
        foreach (['curl', 'gd', 'mbstring', 'fileinfo', 'pdo_mysql'] as $extension) {
            $add('PHP ' . $extension, extension_loaded($extension), $extension . ' 확장 활성화 필요');
        }
        $add('GD FreeType', function_exists('imagettftext'), '한글 커버를 위한 GD FreeType 지원 필요');
        $add('Font', is_file($config['font_path']) && is_readable($config['font_path']), $config['font_path']);

        $canExec = is_callable('exec');
        $add('exec', $canExec, 'PHP에서 FFmpeg 실행 허용 필요');
        if ($canExec) {
            foreach (['ffmpeg_path' => 'FFmpeg', 'ffprobe_path' => 'FFprobe'] as $key => $label) {
                $output = [];
                exec(escapeshellarg($config[$key]) . ' -version 2>&1', $output, $code);
                $add($label, $code === 0, $config[$key]);
                if ($key === 'ffmpeg_path' && $code === 0) {
                    $filters = [];
                    exec(escapeshellarg($config[$key]) . ' -hide_banner -filters 2>&1', $filters, $filterCode);
                    $filterText = implode("\n", $filters);
                    foreach (['ass', 'scale', 'crop', 'boxblur', 'overlay', 'loudnorm', 'sidechaincompress', 'afade', 'amix', 'alimiter'] as $filter) {
                        $add('FFmpeg ' . $filter, $filterCode === 0 && preg_match('/^\s*\S+\s+' . $filter . '\s/m', $filterText) === 1, $filter . ' 필터 지원 필요');
                    }
                    $encoders = [];
                    exec(escapeshellarg($config[$key]) . ' -hide_banner -encoders 2>&1', $encoders, $encoderCode);
                    foreach (['libx264', 'aac'] as $encoder) {
                        $add('FFmpeg ' . $encoder, $encoderCode === 0 && preg_match('/^\s*\S+\s+' . $encoder . '\s/m', implode("\n", $encoders)) === 1, $encoder . ' 인코더 지원 필요');
                    }
                }
            }
        }

        $outputRoot = str_replace('\\', '/', $config['output_root']);
        $absolute = preg_match('#^(?:/|[A-Za-z]:/)#', $outputRoot) === 1;
        $ancestor = $outputRoot;
        $missing = [];
        while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
            array_unshift($missing, basename($ancestor));
            $ancestor = dirname($ancestor);
        }
        $resolvedAncestor = realpath($ancestor);
        $publicRoot = str_replace('\\', '/', $config['public_root']);
        $resolvedOutput = $resolvedAncestor === false ? '' : str_replace('\\', '/', $resolvedAncestor);
        if ($missing !== []) {
            $resolvedOutput .= '/' . implode('/', $missing);
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $resolvedOutput = strtolower($resolvedOutput);
            $publicRoot = strtolower($publicRoot);
        }
        $insidePublic = $absolute && !in_array('..', explode('/', $outputRoot), true)
            && ($resolvedOutput === $publicRoot || str_starts_with($resolvedOutput, $publicRoot . '/'));
        $add('Output URL', $insidePublic, 'SHORTS_OUTPUT_ROOT는 이 배포본의 public 내부 절대 경로여야 합니다: ' . $config['output_root']);
        $add('Output writable', is_dir($ancestor) && is_writable($ancestor), '실행 사용자에게 출력 폴더 또는 가장 가까운 상위 폴더의 쓰기 권한 필요');

        if ($config['bgm_path'] !== '' && (!is_file($config['bgm_path']) || !is_readable($config['bgm_path']))) {
            $checks[] = ['name' => 'BGM', 'status' => 'warning', 'message' => 'SHORTS_BGM_PATH 파일을 읽을 수 없습니다. 기본 음악을 사용하거나 스튜디오에서 직접 업로드하세요.'];
        }

        return $checks;
    }

    public function assertReady(string $provider = ''): void
    {
        $errors = array_filter($this->inspect($provider), static fn (array $check): bool => $check['status'] === 'error');
        if ($errors !== []) {
            throw new RuntimeException("영상 생성 환경을 먼저 확인하세요. API 생성 요청은 실행하지 않았습니다.\n" . implode("\n", array_map(
                static fn (array $check): string => $check['name'] . ': ' . $check['message'],
                $errors
            )));
        }
    }
}
