<?php

namespace App\Models;

use App\Database;
use PDO;

class TodoModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getAll(array $filters = []): array
    {
        [$whereSql, $params] = $this->buildFilterSql($filters);

        $stmt = $this->db->prepare("
            SELECT t.*, c.name AS company_name, p.name AS project_name
            FROM admin_todos t
            LEFT JOIN companies c ON c.id = t.company_id
            LEFT JOIN projects p ON p.id = t.project_id
            {$whereSql}
            ORDER BY
                CASE
                    WHEN t.status = 'done' THEN 4
                    WHEN t.due_date IS NOT NULL AND t.due_date < CURDATE() THEN 0
                    WHEN t.priority = 'high' THEN 1
                    WHEN t.status = 'doing' THEN 2
                    ELSE 3
                END ASC,
                t.due_date IS NULL ASC,
                t.due_date ASC,
                t.id DESC
        ");
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTodo(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, c.name AS company_name, p.name AS project_name
            FROM admin_todos t
            LEFT JOIN companies c ON c.id = t.company_id
            LEFT JOIN projects p ON p.id = t.project_id
            WHERE t.id = :id
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function getSummary(): array
    {
        $stmt = $this->db->query("
            SELECT
                COUNT(*) AS total,
                SUM(status = 'todo') AS todo,
                SUM(status = 'doing') AS doing,
                SUM(status = 'done') AS done,
                SUM(status = 'hold') AS hold,
                SUM(status != 'done' AND due_date IS NOT NULL AND due_date < CURDATE()) AS overdue,
                SUM(status != 'done' AND due_date = CURDATE()) AS today,
                SUM(status != 'done' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS due_7
            FROM admin_todos
        ");
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int)($summary['total'] ?? 0),
            'todo' => (int)($summary['todo'] ?? 0),
            'doing' => (int)($summary['doing'] ?? 0),
            'done' => (int)($summary['done'] ?? 0),
            'hold' => (int)($summary['hold'] ?? 0),
            'overdue' => (int)($summary['overdue'] ?? 0),
            'today' => (int)($summary['today'] ?? 0),
            'due_7' => (int)($summary['due_7'] ?? 0),
        ];
    }

    public function getDashboardItems(int $limit = 8): array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, c.name AS company_name, p.name AS project_name
            FROM admin_todos t
            LEFT JOIN companies c ON c.id = t.company_id
            LEFT JOIN projects p ON p.id = t.project_id
            WHERE t.status != 'done'
            ORDER BY
                CASE
                    WHEN t.due_date IS NOT NULL AND t.due_date < CURDATE() THEN 0
                    WHEN t.due_date = CURDATE() THEN 1
                    WHEN t.priority = 'high' THEN 2
                    WHEN t.status = 'doing' THEN 3
                    ELSE 4
                END ASC,
                t.due_date IS NULL ASC,
                t.due_date ASC,
                t.id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(array $data): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO admin_todos
                (title, content, company_id, project_id, manager, priority, status, due_date, completed_at, created_at, updated_at)
            VALUES
                (:title, :content, :company_id, :project_id, :manager, :priority, :status, :due_date, :completed_at, NOW(), NOW())
        ");

        return $stmt->execute($this->params($data));
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE admin_todos SET
                title = :title,
                content = :content,
                company_id = :company_id,
                project_id = :project_id,
                manager = :manager,
                priority = :priority,
                status = :status,
                due_date = :due_date,
                completed_at = :completed_at,
                updated_at = NOW()
            WHERE id = :id
        ");
        $params = $this->params($data);
        $params[':id'] = $id;

        return $stmt->execute($params);
    }

    public function updateStatus(int $id, string $status): bool
    {
        $completedAt = $status === 'done' ? 'NOW()' : 'NULL';
        $stmt = $this->db->prepare("
            UPDATE admin_todos
            SET status = :status,
                completed_at = {$completedAt},
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM admin_todos WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    private function params(array $data): array
    {
        return [
            ':title' => $data['title'],
            ':content' => $data['content'],
            ':company_id' => $data['company_id'],
            ':project_id' => $data['project_id'],
            ':manager' => $data['manager'],
            ':priority' => $data['priority'],
            ':status' => $data['status'],
            ':due_date' => $data['due_date'],
            ':completed_at' => $data['status'] === 'done' ? ($data['completed_at'] ?? date('Y-m-d H:i:s')) : null,
        ];
    }

    private function buildFilterSql(array $filters): array
    {
        $conditions = [];
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $conditions[] = "(t.title LIKE :q OR t.content LIKE :q OR c.name LIKE :q OR p.name LIKE :q OR t.manager LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        foreach (['status', 'priority', 'manager'] as $key) {
            $value = trim((string)($filters[$key] ?? ''));
            if ($value !== '') {
                $conditions[] = "t.{$key} = :{$key}";
                $params[':' . $key] = $value;
            }
        }

        $due = trim((string)($filters['due'] ?? ''));
        if ($due === 'overdue') {
            $conditions[] = "t.status != 'done' AND t.due_date IS NOT NULL AND t.due_date < CURDATE()";
        } elseif ($due === 'today') {
            $conditions[] = "t.status != 'done' AND t.due_date = CURDATE()";
        } elseif ($due === 'week') {
            $conditions[] = "t.status != 'done' AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        } elseif ($due === 'none') {
            $conditions[] = "t.due_date IS NULL";
        }

        return [
            $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '',
            $params,
        ];
    }
}

?>
