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
            'bgm_path' => trim((string)($_ENV['SHORTS_BGM_PATH'] ?? '')),
        ];
    }

    /** Local checks only: no API calls, directory creation, or database writes. */
    public function inspect(): array
    {
        $config = self::configuration();
        $checks = [];
        $add = static function (string $name, bool $ok, string $message) use (&$checks): void {
            $checks[] = ['name' => $name, 'status' => $ok ? 'ok' : 'error', 'message' => $message];
        };

        $add('API key', trim((string)($_ENV['OPENAI_API_KEY'] ?? '')) !== '', 'OPENAI_API_KEY 설정 여부 (인증·잔액은 별도 확인)');
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
                    foreach (['ass', 'zoompan', 'xfade', 'loudnorm'] as $filter) {
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
            $checks[] = ['name' => 'BGM', 'status' => 'warning', 'message' => 'BGM 파일을 읽을 수 없어 배경음악 없이 생성됩니다.'];
        }

        return $checks;
    }

    public function assertReady(): void
    {
        $errors = array_filter($this->inspect(), static fn (array $check): bool => $check['status'] === 'error');
        if ($errors !== []) {
            throw new RuntimeException("영상 생성 환경을 먼저 확인하세요. API 생성 요청은 실행하지 않았습니다.\n" . implode("\n", array_map(
                static fn (array $check): string => $check['name'] . ': ' . $check['message'],
                $errors
            )));
        }
    }
}
