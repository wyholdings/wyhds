<?php

namespace App\Controllers;

use App\Services\OpenAIClient;
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
        $renderModel = new ShortsRenderModel();
        $renders = $this->hydrateRenders($renderModel->findLatest());
        $flash = null;
        $result = null;
        $models = OpenAIClient::modelConfiguration();
        $environment = ShortsEnvironment::configuration();
        $form = [
            'keyword' => '',
            'tone' => 'clickable',
            'duration' => 60,
            'voice' => $models['tts_voice'],
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $form = [
                'keyword' => trim((string)($_POST['keyword'] ?? '')),
                'tone' => trim((string)($_POST['tone'] ?? 'clickable')),
                'duration' => max(30, min(90, (int)($_POST['duration'] ?? 60))),
                'voice' => trim((string)($_POST['voice'] ?? $models['tts_voice'])),
            ];

            $renderId = $renderModel->create([
                'keyword' => $form['keyword'],
                'status' => 'processing',
                'error_message' => null,
                'output_dir' => null,
                'cover_url' => null,
                'audio_url' => null,
                'video_url' => null,
                'script_json' => null,
            ]);

            try {
                $referenceImagePaths = $this->resolveStudioReferenceImages($_FILES);
                $service = new ShortsAutomationService();
                $result = $service->generate([
                    'keyword' => $form['keyword'],
                    'tone' => $form['tone'],
                    'duration' => $form['duration'],
                    'voice' => $form['voice'],
                    'reference_image_paths' => $referenceImagePaths,
                ]);

                $renderModel->update($renderId, [
                    'keyword' => $form['keyword'],
                    'status' => 'completed',
                    'error_message' => null,
                    'output_dir' => $result['output_dir'],
                    'cover_url' => $result['cover_url'],
                    'audio_url' => $result['audio_url'],
                    'video_url' => $result['video_url'],
                    'script_json' => json_encode($result['script'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                ]);

                $flash = [
                    'type' => 'success',
                    'message' => '쇼츠 생성이 완료되었습니다.',
                ];
            } catch (\Throwable $e) {
                $renderModel->update($renderId, [
                    'keyword' => $form['keyword'],
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'output_dir' => null,
                    'cover_url' => null,
                    'audio_url' => null,
                    'video_url' => null,
                    'script_json' => null,
                ]);

                $flash = [
                    'type' => 'danger',
                    'message' => $e->getMessage(),
                ];
            }

            $renders = $this->hydrateRenders($renderModel->findLatest());
        }

        echo $this->twig->render('admin/shorts/studio.html.twig', [
            'renders' => $renders,
            'flash' => $flash,
            'result' => $result,
            'form' => $form,
            'output_root' => $environment['output_root'],
            'ffmpeg_path' => $environment['ffmpeg_path'],
            'bgm_path' => $environment['bgm_path'],
            'openai_model' => $models['text_model'],
            'tts_model' => $models['tts_model'],
            'image_model' => $models['image_model'],
            'voices' => OpenAIClient::VOICES,
        ]);
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

    private function storeUploadedReferenceImage(?array $file): ?string
    {
        if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('참조 이미지 업로드에 실패했습니다.');
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new \RuntimeException('업로드된 참조 이미지가 올바르지 않습니다.');
        }

        $mime = mime_content_type($tmpPath) ?: '';
        $extension = null;
        switch ($mime) {
            case 'image/jpeg':
                $extension = 'jpg';
                break;
            case 'image/png':
                $extension = 'png';
                break;
            case 'image/webp':
                $extension = 'webp';
                break;
        }

        if ($extension === null) {
            throw new \RuntimeException('참조 이미지는 jpg, png, webp만 가능합니다.');
        }

        $publicRoot = realpath(__DIR__ . '/../../public');
        $uploadDir = $publicRoot . '/uploads/shorts-refs';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
            throw new \RuntimeException('참조 이미지 저장 폴더 생성에 실패했습니다.');
        }

        $filename = 'ref_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $targetPath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($tmpPath, $targetPath)) {
            throw new \RuntimeException('참조 이미지 저장에 실패했습니다.');
        }

        return '/uploads/shorts-refs/' . $filename;
    }

    private function resolveStudioReferenceImages(array $files): array
    {
        $uploadedFront = $this->storeUploadedReferenceImage($files['reference_image'] ?? null);
        $uploadedSide = $this->storeUploadedReferenceImage($files['reference_side_image'] ?? null);
        $uploadedExpression = $this->storeUploadedReferenceImage($files['reference_expression_image'] ?? null);

        $urls = [
            $uploadedFront,
            $uploadedSide,
            $uploadedExpression,
        ];

        $paths = [];
        $publicRoot = realpath(__DIR__ . '/../../public');
        foreach ($urls as $url) {
            if (!$url) {
                continue;
            }

            $fullPath = $publicRoot . str_replace('/', DIRECTORY_SEPARATOR, $url);
            if (is_file($fullPath)) {
                $paths[] = $fullPath;
            }
        }

        return $paths;
    }
}
