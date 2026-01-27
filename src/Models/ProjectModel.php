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
    }

    public function getAll(): array
    {
        $stmt = $this->db->query("SELECT * FROM projects ORDER BY id DESC, created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProject(int $projectId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM projects WHERE id = :id");
        $stmt->bindValue(':id', $projectId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function insert(array $data): bool
    {
        $sql = "
            INSERT INTO projects
                (name, client_name, site_url, start_date, url_expiry_date, ssl_expiry_date, hosting_expiry_date, maintenance_expiry_date, manager, status, memo, created_at, updated_at)
            VALUES
                (:name, :client_name, :site_url, :start_date, :url_expiry_date, :ssl_expiry_date, :hosting_expiry_date, :maintenance_expiry_date, :manager, :status, :memo, NOW(), NOW())
        ";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
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
                INDEX idx_status (status),
                INDEX idx_url_expiry (url_expiry_date),
                INDEX idx_ssl_expiry (ssl_expiry_date),
                INDEX idx_hosting_expiry (hosting_expiry_date),
                INDEX idx_maintenance_expiry (maintenance_expiry_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}

?>
