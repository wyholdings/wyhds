<?php

namespace App\Models;

use App\Database;
use PDO;

class ShortsRenderModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->createTableIfNotExists();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO shorts_renders (
                project_id,
                keyword,
                status,
                error_message,
                output_dir,
                cover_url,
                audio_url,
                video_url,
                script_json,
                created_at,
                updated_at
            ) VALUES (
                :project_id,
                :keyword,
                :status,
                :error_message,
                :output_dir,
                :cover_url,
                :audio_url,
                :video_url,
                :script_json,
                NOW(),
                NOW()
            )
        ");

        $stmt->execute([
            ':project_id' => $data['project_id'],
            ':keyword' => $data['keyword'],
            ':status' => $data['status'],
            ':error_message' => $data['error_message'],
            ':output_dir' => $data['output_dir'],
            ':cover_url' => $data['cover_url'],
            ':audio_url' => $data['audio_url'],
            ':video_url' => $data['video_url'],
            ':script_json' => $data['script_json'],
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE shorts_renders SET
                project_id = :project_id,
                keyword = :keyword,
                status = :status,
                error_message = :error_message,
                output_dir = :output_dir,
                cover_url = :cover_url,
                audio_url = :audio_url,
                video_url = :video_url,
                script_json = :script_json,
                updated_at = NOW()
            WHERE id = :id
        ");

        return $stmt->execute([
            ':id' => $id,
            ':project_id' => $data['project_id'],
            ':keyword' => $data['keyword'],
            ':status' => $data['status'],
            ':error_message' => $data['error_message'],
            ':output_dir' => $data['output_dir'],
            ':cover_url' => $data['cover_url'],
            ':audio_url' => $data['audio_url'],
            ':video_url' => $data['video_url'],
            ':script_json' => $data['script_json'],
        ]);
    }

    public function findLatest(int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, p.channel_name
            FROM shorts_renders r
            LEFT JOIN shorts_projects p ON p.id = r.project_id
            ORDER BY r.id DESC
            LIMIT :render_limit
        ");
        $stmt->bindValue(':render_limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, p.channel_name
            FROM shorts_renders r
            LEFT JOIN shorts_projects p ON p.id = r.project_id
            WHERE r.id = :id
        ");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function createTableIfNotExists(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS shorts_renders (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED DEFAULT NULL,
                keyword VARCHAR(255) NOT NULL,
                status VARCHAR(20) DEFAULT 'queued',
                error_message TEXT DEFAULT NULL,
                output_dir VARCHAR(255) DEFAULT NULL,
                cover_url VARCHAR(255) DEFAULT NULL,
                audio_url VARCHAR(255) DEFAULT NULL,
                video_url VARCHAR(255) DEFAULT NULL,
                script_json LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_project_id (project_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}
