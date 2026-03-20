<?php

namespace App\Controllers;

use App\Models\ShortsProjectModel;
use App\Models\ShortsRenderModel;
use App\Services\ShortsAutomationService;
use Twig\Environment;

class ShortsProjectController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function list(): void
    {
        $model = new ShortsProjectModel();
        $projects = $model->getAll();
        $counts = $model->getStatusCounts();

        echo $this->twig->render('admin/shorts/list.html.twig', [
            'projects' => $projects,
            'counts' => $counts,
        ]);
    }

    public function studio(): void
    {
        $projectModel = new ShortsProjectModel();
        $renderModel = new ShortsRenderModel();
        $projects = $projectModel->getAll();
        $renders = $this->hydrateRenders($renderModel->findLatest());
        $flash = null;
        $result = null;
        $form = [
            'project_id' => '',
            'keyword' => '',
            'tone' => 'clickable',
            'duration' => 30,
            'voice' => (string)($_ENV['OPENAI_TTS_VOICE'] ?? 'alloy'),
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $form = [
                'project_id' => trim((string)($_POST['project_id'] ?? '')),
                'keyword' => trim((string)($_POST['keyword'] ?? '')),
                'tone' => trim((string)($_POST['tone'] ?? 'clickable')),
                'duration' => max(15, min(60, (int)($_POST['duration'] ?? 30))),
                'voice' => trim((string)($_POST['voice'] ?? ((string)($_ENV['OPENAI_TTS_VOICE'] ?? 'alloy')))),
            ];

            $projectId = $form['project_id'] !== '' ? (int)$form['project_id'] : null;
            $project = $projectId ? $projectModel->getById($projectId) : null;

            $renderId = $renderModel->create([
                'project_id' => $projectId,
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
                $service = new ShortsAutomationService();
                $result = $service->generate([
                    'keyword' => $form['keyword'],
                    'tone' => $form['tone'],
                    'duration' => $form['duration'],
                    'voice' => $form['voice'],
                    'project' => $project,
                ]);

                $renderModel->update($renderId, [
                    'project_id' => $projectId,
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
                    'project_id' => $projectId,
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
            'projects' => $projects,
            'renders' => $renders,
            'flash' => $flash,
            'result' => $result,
            'form' => $form,
            'ffmpeg_path' => (string)($_ENV['FFMPEG_PATH'] ?? ''),
            'openai_model' => (string)($_ENV['OPENAI_MODEL'] ?? 'gpt-5'),
            'tts_model' => (string)($_ENV['OPENAI_TTS_MODEL'] ?? 'gpt-4o-mini-tts'),
        ]);
    }

    public function add(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $model = new ShortsProjectModel();
            $model->insert($this->normalizeData($_POST));

            header('Location: /admin/shorts/list');
            exit;
        }

        echo $this->twig->render('admin/shorts/add.html.twig');
    }

    public function view($id): void
    {
        $model = new ShortsProjectModel();
        $project = $model->getById((int)$id);

        if (!$project) {
            http_response_code(404);
            echo $this->twig->render('errors/404.html.twig');
            exit;
        }

        echo $this->twig->render('admin/shorts/view.html.twig', [
            'project' => $project,
        ]);
    }

    public function edit($id): void
    {
        $model = new ShortsProjectModel();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $model->update((int)$id, $this->normalizeData($_POST));
            header("Location: /admin/shorts/{$id}/view");
            exit;
        }

        $project = $model->getById((int)$id);
        if (!$project) {
            http_response_code(404);
            echo $this->twig->render('errors/404.html.twig');
            exit;
        }

        echo $this->twig->render('admin/shorts/add.html.twig', [
            'project' => $project,
        ]);
    }

    public function delete($id): void
    {
        $model = new ShortsProjectModel();
        $model->delete((int)$id);

        header('Location: /admin/shorts/list');
        exit;
    }

    private function normalizeData(array $input): array
    {
        $avgLength = (int)($input['avg_length_seconds'] ?? 30);

        return [
            'channel_name' => trim((string)($input['channel_name'] ?? '')),
            'niche' => trim((string)($input['niche'] ?? '')),
            'target_audience' => trim((string)($input['target_audience'] ?? '')),
            'content_format' => trim((string)($input['content_format'] ?? 'facts')),
            'source_platform' => trim((string)($input['source_platform'] ?? 'reddit')),
            'automation_stack' => trim((string)($input['automation_stack'] ?? '')),
            'publishing_frequency' => trim((string)($input['publishing_frequency'] ?? '')),
            'avg_length_seconds' => $avgLength > 0 ? $avgLength : 30,
            'cta_text' => trim((string)($input['cta_text'] ?? '')),
            'monetization_model' => trim((string)($input['monetization_model'] ?? 'adsense')),
            'status' => trim((string)($input['status'] ?? 'planning')),
            'topic_prompt' => trim((string)($input['topic_prompt'] ?? '')),
            'script_prompt' => trim((string)($input['script_prompt'] ?? '')),
            'thumbnail_formula' => trim((string)($input['thumbnail_formula'] ?? '')),
            'compliance_notes' => trim((string)($input['compliance_notes'] ?? '')),
            'memo' => trim((string)($input['memo'] ?? '')),
        ];
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
}
