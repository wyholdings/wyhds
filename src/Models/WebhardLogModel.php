<?php

namespace App\Models;

use App\Database;
use PDO;

class WebhardLogModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->createTableIfNotExists();
    }

    public function insertLog(string $action, string $path, string $status, string $detail, int $adminId, string $ip): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO webhard_logs (action, path, status, detail, admin_id, ip)
            VALUES (:action, :path, :status, :detail, :admin_id, :ip)
        ");
        $stmt->execute([
            'action'    => $action,
            'path'      => $path,
            'status'    => $status,
            'detail'    => $detail,
            'admin_id'  => $adminId,
            'ip'        => $ip,
        ]);
    }

    private function createTableIfNotExists(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS webhard_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                action VARCHAR(32) NOT NULL,
                path TEXT NOT NULL,
                status VARCHAR(16) NOT NULL,
                detail TEXT DEFAULT '',
                admin_id INT UNSIGNED DEFAULT 0,
                ip VARCHAR(45) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    public function getLogs(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT id, action, path, status, detail, admin_id, ip, created_at
            FROM webhard_logs
            ORDER BY id DESC
            LIMIT :offset, :limit
        ");
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countLogs(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM webhard_logs");
        return (int)$stmt->fetchColumn();
    }
}
