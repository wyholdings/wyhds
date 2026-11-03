<?php

namespace App\Controllers;

use App\Services\OpenAIClient;
use App\Services\GoogleSpeechClient;
use App\Services\ShortsEnvironment;
use App\Models\ShortsRenderModel;
use App\Services\ShortsAutomationService;
use Twig\Environment;

class ShortsStudioController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function studio(): void
    {
        $models = OpenAIClient::modelConfiguration();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->submitGeneration($models);
            return;
        }

        $renderModel = new ShortsRenderModel();
        $renders = $this->hydrateRenders($renderModel->findLatest());
        $flash = $_SESSION['shorts_flash'] ?? null;
        unset($_SESSION['shorts_flash']);
        $result = null;
        $environment = ShortsEnvironment::configuration();
        $google = GoogleSpeechClient::configuration();
        $form = array_merge([
            'keyword' => '',
            'stock_keyword' => '',
            'tone' => 'clickable',
            'duration' => 60,
            'tts_provider' => $google['provider'],
            'voice' => $google['provider'] === 'google' ? $google['voice'] : $models['tts_voice'],
            'speech_style' => 'natural',
            'speech_speed' => 1.0,
            'bgm_mode' => 'default',
            'bgm_volume' => 12,
        ], $_SESSION['shorts_form'] ?? []);
        $allowedVoices = $form['tts_provider'] === 'google' ? array_keys(GoogleSpeechClient::VOICES) : OpenAIClient::VOICES;
        if (!in_array($form['voice'], $allowedVoices, true)) {
            $form['voice'] = $form['tts_provider'] === 'google' ? $google['voice'] : $models['tts_voice'];
        }

        $selectedId = filter_var($_GET['render'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($selectedId !== false && $selectedId !== null) {
            $render = $renderModel->findById($selectedId);
            if ($render && $render['status'] === 'completed') {
                $script = json_decode((string)$render['script_json'], true);
                $script = is_array($script) ? $script : [];
                $result = [
                    'video_url' => $render['video_url'],
                    'cover_url' => $render['cover_url'],
                    'audio_url' => $render['audio_url'],
                    'subtitle_url' => $script['_subtitle_url'] ?? null,
                    'scene_image_urls' => $script['_scene_image_urls'] ?? [],
                    'script' => $script,
                ];
            } elseif ($render && $render['status'] === 'failed') {
                $flash = ['type' => 'danger', 'message' => $render['error_message'] ?: '영상 생성에 실패했습니다.'];
            } elseif ($render) {
                $flash = ['type' => 'info', 'message' => '영상 생성 요청을 처리 중입니다. 새로고침해도 추가로 생성하지 않습니다.'];
            } else {
                $flash = ['type' => 'warning', 'message' => '생성 기록을 찾을 수 없습니다.'];
            }
        }

        // Keep multiple tabs usable while retaining consumed requests for replay protection.
        $requests = $_SESSION['shorts_requests'] ?? [];
        if (count($requests) >= 32) {
            unset($requests[array_key_first($requests)]);
        }
        $requestToken = bin2hex(random_bytes(32));
        $requests[$requestToken] = ['used' => false, 'render_id' => null];
        $_SESSION['shorts_requests'] = $requests;

        echo $this->twig->render('admin/shorts/studio.html.twig', [
            'renders' => $renders,
            'flash' => $flash,
            'result' => $result,
            'form' => $form,
            'request_token' => $requestToken,
            'output_root' => $environment['output_root'],
            'ffmpeg_path' => $environment['ffmpeg_path'],
            'bgm_path' => $environment['bgm_path'],
            'openai_model' => $models['text_model'],
            'tts_model' => $models['tts_model'],
            'pixabay_configured' => trim((string)($_ENV['PIXABAY_API_KEY'] ?? '')) !== '',
            'pixabay_cache_root' => $environment['pixabay_cache_root'],
            'voices' => OpenAIClient::VOICES,
            'google_voices' => GoogleSpeechClient::VOICES,
            'google_tts_configured' => trim((string)($_ENV['GOOGLE_TTS_API_KEY'] ?? '')) !== '',
        ]);
    }

    private function submitGeneration(array $models): void
    {
        $csrf = $_POST['csrf_token'] ?? null;
        $token = $_POST['request_token'] ?? null;
        if (!is_string($csrf) || !\verify_csrf_token($csrf)
            || !is_string($token) || !isset($_SESSION['shorts_requests'][$token])) {
            $_SESSION['shorts_flash'] = ['type' => 'warning', 'message' => '요청 정보가 만료됐습니다. 다시 열린 화면에서 생성 버튼을 눌러주세요.'];
            $this->redirectToRender(null);
            return;
        }

        $request = $_SESSION['shorts_requests'][$token];
        if ($request['used']) {
            $this->redirectToRender($request['render_id']);
            return;
        }

        // Reserve under the PHP session lock, then persist before paid API work.
        $_SESSION['shorts_requests'][$token]['used'] = true;
        $form = [
            'keyword' => trim((string)($_POST['keyword'] ?? '')),
            'stock_keyword' => mb_substr(trim((string)($_POST['stock_keyword'] ?? '')), 0, 100),
            'tone' => trim((string)($_POST['tone'] ?? 'clickable')),
            'duration' => max(30, min(90, (int)($_POST['duration'] ?? 60))),
            'tts_provider' => (string)($_POST['tts_provider'] ?? GoogleSpeechClient::configuration()['provider']),
            'voice' => trim((string)($_POST['voice'] ?? GoogleSpeechClient::configuration()['voice'])),
            'speech_style' => in_array($_POST['speech_style'] ?? '', ['natural', 'calm', 'bright'], true) ? $_POST['speech_style'] : 'natural',
            'speech_speed' => max(0.85, min(1.15, (float)($_POST['speech_speed'] ?? 1.0))),
            'bgm_mode' => in_array($_POST['bgm_mode'] ?? '', ['default', 'upload', 'off'], true) ? $_POST['bgm_mode'] : 'default',
            'bgm_volume' => max(0, min(30, (int)($_POST['bgm_volume'] ?? 12))),
        ];
        $_SESSION['shorts_form'] = $form;
        $renderId = null;
        $renderModel = null;
        $sessionReleased = false;
        $flash = null;
        try {
            $renderModel = new ShortsRenderModel();
            $renderId = $renderModel->create([
                'keyword' => $form['keyword'], 'status' => 'processing', 'error_message' => null,
                'output_dir' => null, 'cover_url' => null, 'audio_url' => null, 'video_url' => null, 'script_json' => null,
            ]);
            $_SESSION['shorts_requests'][$token]['render_id'] = $renderId;
            if (session_status() === PHP_SESSION_ACTIVE) {
                if (!session_write_close()) {
                    throw new \RuntimeException('생성 요청 상태를 저장하지 못했습니다. 다시 시도해주세요.');
                }
                $sessionReleased = true;
            }
            $bgmPath = $form['bgm_mode'] === 'upload' ? $this->storeUploadedBgm($_FILES['bgm_file'] ?? null) : '';
            $result = (new ShortsAutomationService())->generate([
                'keyword' => $form['keyword'], 'tone' => $form['tone'], 'duration' => $form['duration'],
                'voice' => $form['voice'], 'speech_style' => $form['speech_style'], 'speech_speed' => $form['speech_speed'],
                'stock_keyword' => $form['stock_keyword'],
                'tts_provider' => $form['tts_provider'],
                'bgm_mode' => $form['bgm_mode'], 'bgm_volume' => $form['bgm_volume'], 'bgm_path' => $bgmPath,
            ]);
            $script = $result['script'];
            $script['_scene_image_urls'] = $result['scene_image_urls'] ?? [];
            $script['_subtitle_url'] = $result['subtitle_url'] ?? null;
            $renderModel->update($renderId, [
                'keyword' => $form['keyword'], 'status' => 'completed', 'error_message' => null,
                'output_dir' => $result['output_dir'], 'cover_url' => $result['cover_url'],
                'audio_url' => $result['audio_url'], 'video_url' => $result['video_url'],
                'script_json' => json_encode($script, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            ]);
            $flash = ['type' => 'success', 'message' => '쇼츠 생성이 완료되었습니다.'];
        } catch (\Throwable $e) {
            if ($renderId !== null && $renderModel !== null) {
                $renderModel->update($renderId, [
                    'keyword' => $form['keyword'], 'status' => 'failed', 'error_message' => $e->getMessage(),
                    'output_dir' => null, 'cover_url' => null, 'audio_url' => null, 'video_url' => null, 'script_json' => null,
                ]);
            }
            $flash = ['type' => 'danger', 'message' => $e->getMessage()];
        } finally {
            if ($sessionReleased && session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
        }
        $_SESSION['shorts_flash'] = $flash;
        $this->redirectToRender($renderId);
    }

    private function redirectToRender(?int $renderId): void
    {
        $url = '/admin/shorts/studio' . ($renderId !== null ? '?render=' . $renderId : '');
        header('Location: ' . $url, true, 303);
    }

    private function hydrateRenders(array $renders): array
    {
        foreach ($renders as &$render) {
            $script = json_decode((string)($render['script_json'] ?? ''), true);
            $render['script'] = is_array($script) ? $script : null;
        }
        unset($render);

        return $renders;
    }

    private function storeUploadedBgm(?array $file): string
    {
        if (!$file || !is_scalar($file['error'] ?? null) || (int)$file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('배경음악 파일을 업로드하세요. 실패한 경우 PHP upload_max_filesize와 post_max_size도 확인하세요.');
        }
        $tmp = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        if (!is_uploaded_file($tmp) || filesize($tmp) < 1 || filesize($tmp) > 20 * 1024 * 1024) {
            throw new \RuntimeException('배경음악은 20MB 이하의 오디오 파일이어야 합니다.');
        }
        $types = ['audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
            'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'video/mp4' => 'm4a', 'audio/ogg' => 'ogg', 'application/ogg' => 'ogg',
            'audio/flac' => 'flac', 'audio/x-flac' => 'flac'];
        $mime = (string)mime_content_type($tmp);
        if (!isset($types[$mime])) { throw new \RuntimeException('배경음악은 MP3, WAV, M4A, OGG, FLAC 형식으로 올려주세요.'); }
        $dir = ShortsEnvironment::configuration()['output_root'] . '/music';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) { throw new \RuntimeException('배경음악 업로드 폴더에 쓰기 권한이 필요합니다.'); }
        $target = $dir . '/bgm_' . bin2hex(random_bytes(12)) . '.' . $types[$mime];
        if (!move_uploaded_file($tmp, $target)) { throw new \RuntimeException('배경음악 파일을 저장하지 못했습니다.'); }
        return $target;
    }
}
