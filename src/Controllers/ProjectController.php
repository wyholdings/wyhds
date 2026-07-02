<?php

namespace App\Controllers;

use App\Models\CompanyModel;
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
        $filters = $this->filtersFromQuery($_GET);
        $projects = $model->getAll($filters);

        foreach ($projects as &$project) {
            $project['url_expiry_days'] = $this->daysUntil($project['url_expiry_date'] ?? null);
            $project['ssl_expiry_days'] = $this->daysUntil($project['ssl_expiry_date'] ?? null);
            $project['hosting_expiry_days'] = $this->daysUntil($project['hosting_expiry_date'] ?? null);
            $project['maintenance_expiry_days'] = $this->daysUntil($project['maintenance_expiry_date'] ?? null);
        }
        unset($project);

        echo $this->twig->render('admin/project/list.html.twig', [
            'projects' => $projects,
            'filters' => $filters,
            'summary' => $model->getSummary(),
            'companies' => (new CompanyModel())->getAll(),
        ]);
    }

    public function add(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requireValidCsrf();
            $model = new ProjectModel();
            $data = $this->normalizeData($_POST);
            $errors = $this->validateData($data);

            if ($errors) {
                http_response_code(422);
                echo $this->twig->render('admin/project/add.html.twig', [
                    'project' => $data,
                    'companies' => (new CompanyModel())->getAll(),
                    'errors' => $errors,
                ]);
                return;
            }

            $model->insert($data);
            header('Location: /admin/project/list');
            exit;
        }

        echo $this->twig->render('admin/project/add.html.twig', [
            'companies' => (new CompanyModel())->getAll(),
        ]);
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
        $project['hosting_expiry_days'] = $this->daysUntil($project['hosting_expiry_date'] ?? null);
        $project['maintenance_expiry_days'] = $this->daysUntil($project['maintenance_expiry_date'] ?? null);

        echo $this->twig->render('admin/project/view.html.twig', [
            'project' => $project,
        ]);
    }

    public function edit($id): void
    {
        $model = new ProjectModel();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requireValidCsrf();
            $data = $this->normalizeData($_POST);
            $errors = $this->validateData($data);

            if ($errors) {
                $data['id'] = $id;
                http_response_code(422);
                echo $this->twig->render('admin/project/add.html.twig', [
                    'project' => $data,
                    'companies' => (new CompanyModel())->getAll(),
                    'errors' => $errors,
                ]);
                return;
            }

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

        echo $this->twig->render('admin/project/add.html.twig', [
            'project' => $project,
            'companies' => (new CompanyModel())->getAll(),
        ]);
    }

    public function delete($id): void
    {
        $this->requireValidCsrf();
        $model = new ProjectModel();
        $model->deleteProject((int)$id);
        header('Location: /admin/project/list');
        exit;
    }

    private function normalizeData(array $input): array
    {
        return [
            'name'            => trim((string)($input['name'] ?? '')),
            'company_id'      => $this->nullableInt($input['company_id'] ?? null),
            'client_name'     => trim((string)($input['client_name'] ?? '')),
            'site_url'        => trim((string)($input['site_url'] ?? '')),
            'start_date'      => $this->nullableDate($input['start_date'] ?? null),
            'url_expiry_date' => $this->nullableDate($input['url_expiry_date'] ?? null),
            'ssl_expiry_date' => $this->nullableDate($input['ssl_expiry_date'] ?? null),
            'hosting_expiry_date' => $this->nullableDate($input['hosting_expiry_date'] ?? null),
            'maintenance_expiry_date' => $this->nullableDate($input['maintenance_expiry_date'] ?? null),
            'manager'         => trim((string)($input['manager'] ?? '')),
            'status'          => $input['status'] ?? 'active',
            'memo'            => trim((string)($input['memo'] ?? '')),
        ];
    }

    private function validateData(array $data): array
    {
        $errors = [];

        if ($data['name'] === '') {
            $errors[] = '프로젝트명을 입력해주세요.';
        }

        if ($data['site_url'] !== '' && !filter_var($data['site_url'], FILTER_VALIDATE_URL)) {
            $errors[] = '사이트 URL 형식을 확인해주세요.';
        }

        if (!in_array($data['status'], ['active', 'hold', 'inactive', 'expired'], true)) {
            $errors[] = '상태값을 확인해주세요.';
        }

        foreach (['url_expiry_date', 'ssl_expiry_date', 'hosting_expiry_date', 'maintenance_expiry_date'] as $dateKey) {
            if ($data['start_date'] && $data[$dateKey] && $data[$dateKey] < $data['start_date']) {
                $errors[] = '만료일은 프로젝트 시작일 이후여야 합니다.';
                break;
            }
        }

        return $errors;
    }

    private function filtersFromQuery(array $query): array
    {
        return [
            'q' => trim((string)($query['q'] ?? '')),
            'status' => trim((string)($query['status'] ?? '')),
            'expiry' => trim((string)($query['expiry'] ?? '')),
            'company_id' => (int)($query['company_id'] ?? 0),
        ];
    }

    private function nullableInt($value): ?int
    {
        $id = (int)$value;
        return $id > 0 ? $id : null;
    }

    private function requireValidCsrf(): void
    {
        if (!\verify_csrf_token($_POST['csrf_token'] ?? '')) {
            http_response_code(400);
            echo 'Invalid CSRF token.';
            exit;
        }
    }

    private function nullableDate($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '0000-00-00') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function daysUntil(?string $date): ?int
    {
        if (!$date || $date === '0000-00-00') {
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
