<?php

namespace App\Models;

use App\Database;
use PDO;

class VisitorLogModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->createTableIfNotExists();
    }

    public function createLog(string $path, string $query, string $referer, string $userAgent, string $ip, string $sessionId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO visitor_logs (path, query_string, referer, user_agent, ip, session_id)
            VALUES (:path, :query_string, :referer, :user_agent, :ip, :session_id)
        ");
        $stmt->execute([
            'path'         => $path,
            'query_string' => $query,
            'referer'      => $referer,
            'user_agent'   => $userAgent,
            'ip'           => $ip,
            'session_id'   => $sessionId,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function updateDuration(int $id, int $durationSeconds): void
    {
        $stmt = $this->db->prepare("
            UPDATE visitor_logs
            SET duration_seconds = GREATEST(IFNULL(duration_seconds, 0), :duration_seconds),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            'duration_seconds' => $durationSeconds,
            'id'               => $id,
        ]);
    }

    public function getLogs(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT id, path, query_string, referer, user_agent, ip, session_id, duration_seconds, visited_at
            FROM visitor_logs
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
        $stmt = $this->db->query("SELECT COUNT(*) FROM visitor_logs");
        return (int)$stmt->fetchColumn();
    }

    private function createTableIfNotExists(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS visitor_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                path VARCHAR(255) NOT NULL,
                query_string TEXT DEFAULT NULL,
                referer TEXT DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                ip VARCHAR(45) DEFAULT NULL,
                session_id VARCHAR(128) DEFAULT NULL,
                duration_seconds INT UNSIGNED DEFAULT NULL,
                visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_visited_at (visited_at),
                INDEX idx_path (path)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}

?>
