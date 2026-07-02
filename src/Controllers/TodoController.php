<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Models\ProjectModel;
use App\Models\TodoModel;
use Twig\Environment;

class TodoController
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public function list(): void
    {
        $model = new TodoModel();
        $filters = $this->filtersFromQuery($_GET);
        $todos = $model->getAll($filters);

        echo $this->twig->render('admin/todo/list.html.twig', [
            'todos' => $todos,
            'filters' => $filters,
            'summary' => $model->getSummary(),
            'managers' => $this->managerOptions(),
        ]);
    }

    public function add(): void
    {
        $model = new TodoModel();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requireValidCsrf();
            $data = $this->normalizeData($_POST);
            $errors = $this->validateData($data);

            if ($errors) {
                http_response_code(422);
                echo $this->renderForm($data, $errors);
                return;
            }

            $model->insert($data);
            header('Location: /admin/todo/list');
            exit;
        }

        echo $this->renderForm([
            'priority' => 'normal',
            'status' => 'todo',
        ]);
    }

    public function edit($id): void
    {
        $model = new TodoModel();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requireValidCsrf();
            $data = $this->normalizeData($_POST);
            $errors = $this->validateData($data);

            if ($errors) {
                $data['id'] = $id;
                http_response_code(422);
                echo $this->renderForm($data, $errors);
                return;
            }

            $model->update((int)$id, $data);
            header('Location: /admin/todo/list');
            exit;
        }

        $todo = $model->getTodo((int)$id);
        if (!$todo) {
            http_response_code(404);
            echo $this->twig->render('errors/404.html.twig');
            exit;
        }

        echo $this->renderForm($todo);
    }

    public function status($id): void
    {
        $this->requireValidCsrf();

        $status = trim((string)($_POST['status'] ?? ''));
        if (in_array($status, ['todo', 'doing', 'done', 'hold'], true)) {
            (new TodoModel())->updateStatus((int)$id, $status);
        }

        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/admin/todo/list'));
        exit;
    }

    public function delete($id): void
    {
        $this->requireValidCsrf();
        (new TodoModel())->delete((int)$id);
        header('Location: /admin/todo/list');
        exit;
    }

    private function renderForm(array $todo, array $errors = []): string
    {
        return $this->twig->render('admin/todo/form.html.twig', [
            'todo' => $todo,
            'errors' => $errors,
            'companies' => (new CompanyModel())->getAll(),
            'projects' => (new ProjectModel())->getAll(),
            'managers' => $this->managerOptions(),
        ]);
    }

    private function filtersFromQuery(array $query): array
    {
        return [
            'q' => trim((string)($query['q'] ?? '')),
            'status' => trim((string)($query['status'] ?? '')),
            'priority' => trim((string)($query['priority'] ?? '')),
            'manager' => trim((string)($query['manager'] ?? '')),
            'due' => trim((string)($query['due'] ?? '')),
        ];
    }

    private function normalizeData(array $input): array
    {
        return [
            'title' => trim((string)($input['title'] ?? '')),
            'content' => trim((string)($input['content'] ?? '')),
            'company_id' => $this->nullableInt($input['company_id'] ?? null),
            'project_id' => $this->nullableInt($input['project_id'] ?? null),
            'manager' => trim((string)($input['manager'] ?? '')),
            'priority' => $input['priority'] ?? 'normal',
            'status' => $input['status'] ?? 'todo',
            'due_date' => $this->nullableDate($input['due_date'] ?? null),
            'completed_at' => null,
        ];
    }

    private function validateData(array $data): array
    {
        $errors = [];

        if ($data['title'] === '') {
            $errors[] = '할 일 제목을 입력해주세요.';
        }

        if (!in_array($data['priority'], ['high', 'normal', 'low'], true)) {
            $errors[] = '우선순위를 확인해주세요.';
        }

        if (!in_array($data['status'], ['todo', 'doing', 'done', 'hold'], true)) {
            $errors[] = '상태값을 확인해주세요.';
        }

        return $errors;
    }

    private function managerOptions(): array
    {
        return array_values(array_unique(array_filter(array_merge(
            (new CompanyModel())->getManagers(),
            (new ProjectModel())->getManagers()
        ))));
    }

    private function requireValidCsrf(): void
    {
        if (!\verify_csrf_token($_POST['csrf_token'] ?? '')) {
            http_response_code(400);
            echo 'Invalid CSRF token.';
            exit;
        }
    }

    private function nullableInt($value): ?int
    {
        $id = (int)$value;
        return $id > 0 ? $id : null;
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
}

?>
