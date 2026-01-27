<?php

namespace App\Controllers;

use App\Models\ProjectModel;
use Twig\Environment;

class ProjectController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function list(): void
    {
        $model = new ProjectModel();
        $projects = $model->getAll();

        foreach ($projects as &$project) {
            $project['url_expiry_days'] = $this->daysUntil($project['url_expiry_date'] ?? null);
            $project['ssl_expiry_days'] = $this->daysUntil($project['ssl_expiry_date'] ?? null);
        }
        unset($project);

        echo $this->twig->render('admin/project/list.html.twig', [
            'projects' => $projects,
        ]);
    }

    public function add(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $model = new ProjectModel();
            $data = $this->normalizeData($_POST);
            $model->insert($data);
            header('Location: /admin/project/list');
            exit;
        }

        echo $this->twig->render('admin/project/add.html.twig');
    }

    public function view($projectId): void
    {
        $model = new ProjectModel();
        $project = $model->getProject((int)$projectId);

        if (!$project) {
            http_response_code(404);
            echo $this->twig->render('errors/404.html.twig');
            exit;
        }

        $project['url_expiry_days'] = $this->daysUntil($project['url_expiry_date'] ?? null);
        $project['ssl_expiry_days'] = $this->daysUntil($project['ssl_expiry_date'] ?? null);

        echo $this->twig->render('admin/project/view.html.twig', [
            'project' => $project,
        ]);
    }

    public function edit($id): void
    {
        $model = new ProjectModel();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = $this->normalizeData($_POST);
            $model->updateProject((int)$id, $data);
            header("Location: /admin/project/{$id}/view");
            exit;
        }

        $project = $model->getProject((int)$id);
        if (!$project) {
            http_response_code(404);
            echo $this->twig->render('errors/404.html.twig');
            exit;
        }

        echo $this->twig->render('admin/project/add.html.twig', ['project' => $project]);
    }

    public function delete($id): void
    {
        $model = new ProjectModel();
        $model->deleteProject((int)$id);
        header('Location: /admin/project/list');
        exit;
    }

    private function normalizeData(array $input): array
    {
        return [
            'name'            => trim((string)($input['name'] ?? '')),
            'client_name'     => trim((string)($input['client_name'] ?? '')),
            'site_url'        => trim((string)($input['site_url'] ?? '')),
            'start_date'      => $input['start_date'] ?? null,
            'url_expiry_date' => $input['url_expiry_date'] ?? null,
            'ssl_expiry_date' => $input['ssl_expiry_date'] ?? null,
            'manager'         => trim((string)($input['manager'] ?? '')),
            'status'          => $input['status'] ?? 'active',
            'memo'            => trim((string)($input['memo'] ?? '')),
        ];
    }

    private function daysUntil(?string $date): ?int
    {
        if (!$date) {
            return null;
        }

        try {
            $target = new \DateTimeImmutable($date);
        } catch (\Exception $e) {
            return null;
        }

        $today = new \DateTimeImmutable('today');
        $diff = $today->diff($target);
        return (int)$diff->format('%r%a');
    }
}

?>
