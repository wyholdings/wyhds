<?php

namespace App\Models;

use App\Database;
use PDO;

class ProjectModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->createTableIfNotExists();
        $this->ensureCompanyLinkColumns();
    }

    public function getAll(array $filters = []): array
    {
        [$whereSql, $params] = $this->buildFilterSql($filters);

        $stmt = $this->db->prepare("
            SELECT p.*, c.name AS company_name, c.manager AS company_manager
            FROM projects p
            LEFT JOIN companies c ON c.id = p.company_id
            {$whereSql}
            ORDER BY p.id DESC, p.created_at DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSummary(): array
    {
        $stmt = $this->db->query("
            SELECT
                COUNT(*) AS total,
                SUM(status = 'active') AS active,
                SUM(status = 'hold') AS hold,
                SUM(status = 'inactive') AS inactive,
                SUM(status = 'expired') AS status_expired,
                SUM(
                    COALESCE(url_expiry_date < CURDATE(), 0)
                    OR COALESCE(ssl_expiry_date < CURDATE(), 0)
                    OR COALESCE(hosting_expiry_date < CURDATE(), 0)
                    OR COALESCE(maintenance_expiry_date < CURDATE(), 0)
                ) AS expired,
                SUM(
                    COALESCE(url_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY), 0)
                    OR COALESCE(ssl_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY), 0)
                    OR COALESCE(hosting_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY), 0)
                    OR COALESCE(maintenance_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY), 0)
                ) AS due_30,
                SUM(url_expiry_date IS NULL AND ssl_expiry_date IS NULL AND hosting_expiry_date IS NULL AND maintenance_expiry_date IS NULL) AS no_expiry
            FROM projects
        ");

        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int)($summary['total'] ?? 0),
            'active' => (int)($summary['active'] ?? 0),
            'hold' => (int)($summary['hold'] ?? 0),
            'inactive' => (int)($summary['inactive'] ?? 0),
            'status_expired' => (int)($summary['status_expired'] ?? 0),
            'expired' => (int)($summary['expired'] ?? 0),
            'due_30' => (int)($summary['due_30'] ?? 0),
            'no_expiry' => (int)($summary['no_expiry'] ?? 0),
        ];
    }

    public function getExpiringSoon(int $limit = 8, int $days = 30): array
    {
        $sql = "
            SELECT p.*, c.name AS company_name, c.manager AS company_manager,
                LEAST(
                    COALESCE(p.url_expiry_date, '9999-12-31'),
                    COALESCE(p.ssl_expiry_date, '9999-12-31'),
                    COALESCE(p.hosting_expiry_date, '9999-12-31'),
                    COALESCE(p.maintenance_expiry_date, '9999-12-31')
                ) AS nearest_expiry_date
            FROM projects p
            LEFT JOIN companies c ON c.id = p.company_id
            WHERE
                COALESCE(p.url_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY), 0)
                OR COALESCE(p.ssl_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY), 0)
                OR COALESCE(p.hosting_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY), 0)
                OR COALESCE(p.maintenance_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY), 0)
            ORDER BY nearest_expiry_date ASC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProject(int $projectId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT p.*, c.name AS company_name, c.manager AS company_manager, c.phone AS company_phone, c.email AS company_email
            FROM projects p
            LEFT JOIN companies c ON c.id = p.company_id
            WHERE p.id = :id
        ");
        $stmt->bindValue(':id', $projectId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getByCompanyId(int $companyId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.*, c.name AS company_name
            FROM projects p
            LEFT JOIN companies c ON c.id = p.company_id
            WHERE p.company_id = :company_id
            ORDER BY p.id DESC, p.created_at DESC
        ");
        $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(array $data): bool
    {
        $sql = "
            INSERT INTO projects
                (company_id, name, client_name, site_url, start_date, url_expiry_date, ssl_expiry_date, hosting_expiry_date, maintenance_expiry_date, manager, status, memo, created_at, updated_at)
            VALUES
                (:company_id, :name, :client_name, :site_url, :start_date, :url_expiry_date, :ssl_expiry_date, :hosting_expiry_date, :maintenance_expiry_date, :manager, :status, :memo, NOW(), NOW())
        ";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':company_id'      => $data['company_id'],
            ':name'            => $data['name'],
            ':client_name'     => $data['client_name'],
            ':site_url'        => $data['site_url'],
            ':start_date'      => $data['start_date'],
            ':url_expiry_date' => $data['url_expiry_date'],
            ':ssl_expiry_date' => $data['ssl_expiry_date'],
            ':hosting_expiry_date'     => $data['hosting_expiry_date'],
            ':maintenance_expiry_date' => $data['maintenance_expiry_date'],
            ':manager'                 => $data['manager'],
            ':status'          => $data['status'],
            ':memo'            => $data['memo'],
        ]);
    }

    public function updateProject(int $id, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE projects SET
                company_id = :company_id,
                name = :name,
                client_name = :client_name,
                site_url = :site_url,
                start_date = :start_date,
                url_expiry_date = :url_expiry_date,
                ssl_expiry_date = :ssl_expiry_date,
                hosting_expiry_date = :hosting_expiry_date,
                maintenance_expiry_date = :maintenance_expiry_date,
                manager = :manager,
                status = :status,
                memo = :memo,
                updated_at = NOW()
            WHERE id = :id
        ");

        $data['id'] = $id;

        return $stmt->execute($data);
    }

    public function deleteProject(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM projects WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    private function createTableIfNotExists(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS projects (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_id INT UNSIGNED DEFAULT NULL,
                name VARCHAR(120) NOT NULL,
                client_name VARCHAR(120) DEFAULT NULL,
                site_url VARCHAR(255) DEFAULT NULL,
                start_date DATE DEFAULT NULL,
                url_expiry_date DATE DEFAULT NULL,
                ssl_expiry_date DATE DEFAULT NULL,
                hosting_expiry_date DATE DEFAULT NULL,
                maintenance_expiry_date DATE DEFAULT NULL,
                manager VARCHAR(80) DEFAULT NULL,
                status VARCHAR(20) DEFAULT 'active',
                memo TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_company_id (company_id),
                INDEX idx_status (status),
                INDEX idx_url_expiry (url_expiry_date),
                INDEX idx_ssl_expiry (ssl_expiry_date),
                INDEX idx_hosting_expiry (hosting_expiry_date),
                INDEX idx_maintenance_expiry (maintenance_expiry_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    private function ensureCompanyLinkColumns(): void
    {
        $stmt = $this->db->query("SHOW COLUMNS FROM projects LIKE 'company_id'");
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->db->exec("ALTER TABLE projects ADD COLUMN company_id INT UNSIGNED DEFAULT NULL AFTER id");
        }

        $stmt = $this->db->query("SHOW INDEX FROM projects WHERE Key_name = 'idx_company_id'");
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->db->exec("ALTER TABLE projects ADD INDEX idx_company_id (company_id)");
        }
    }

    private function buildFilterSql(array $filters): array
    {
        $conditions = [];
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $conditions[] = "(p.name LIKE :q OR p.client_name LIKE :q OR c.name LIKE :q OR p.site_url LIKE :q OR p.manager LIKE :q OR p.memo LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $conditions[] = "p.status = :status";
            $params[':status'] = $status;
        }

        $companyId = (int)($filters['company_id'] ?? 0);
        if ($companyId > 0) {
            $conditions[] = "p.company_id = :company_id";
            $params[':company_id'] = $companyId;
        }

        $expiry = trim((string)($filters['expiry'] ?? ''));
        if ($expiry === 'expired') {
            $conditions[] = "(
                COALESCE(p.url_expiry_date < CURDATE(), 0)
                OR COALESCE(p.ssl_expiry_date < CURDATE(), 0)
                OR COALESCE(p.hosting_expiry_date < CURDATE(), 0)
                OR COALESCE(p.maintenance_expiry_date < CURDATE(), 0)
            )";
        } elseif (in_array($expiry, ['7', '30', '60'], true)) {
            $days = (int)$expiry;
            $conditions[] = "(
                COALESCE(p.url_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$days} DAY), 0)
                OR COALESCE(p.ssl_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$days} DAY), 0)
                OR COALESCE(p.hosting_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$days} DAY), 0)
                OR COALESCE(p.maintenance_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$days} DAY), 0)
            )";
        } elseif ($expiry === 'none') {
            $conditions[] = "p.url_expiry_date IS NULL AND p.ssl_expiry_date IS NULL AND p.hosting_expiry_date IS NULL AND p.maintenance_expiry_date IS NULL";
        }

        return [
            $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '',
            $params,
        ];
    }
}

?>
