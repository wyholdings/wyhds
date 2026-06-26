<?php

namespace App\Models;

use App\Database;
use PDO;

class WebhardShareModel
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
            INSERT INTO webhard_shares
                (token, base_path, can_upload, can_download, password_hash, expires_at, created_by)
            VALUES
                (:token, :base_path, :can_upload, :can_download, :password_hash, :expires_at, :created_by)
        ");
        $stmt->execute([
            'token' => $data['token'],
            'base_path' => $data['base_path'],
            'can_upload' => (int)$data['can_upload'],
            'can_download' => (int)$data['can_download'],
            'password_hash' => $data['password_hash'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => (int)($data['created_by'] ?? 0),
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function getActiveByPath(string $basePath): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM webhard_shares
            WHERE base_path = :base_path
              AND revoked_at IS NULL
            ORDER BY id DESC
        ");
        $stmt->execute(['base_path' => $basePath]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findValidByToken(string $token): ?array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM webhard_shares
            WHERE token = :token
              AND revoked_at IS NULL
              AND (expires_at IS NULL OR expires_at >= NOW())
            LIMIT 1
        ");
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function revoke(int $id): void
    {
        $stmt = $this->db->prepare("
            UPDATE webhard_shares
            SET revoked_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute(['id' => $id]);
    }

    private function createTableIfNotExists(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS webhard_shares (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                token VARCHAR(128) NOT NULL UNIQUE,
                base_path TEXT NOT NULL,
                can_upload TINYINT(1) NOT NULL DEFAULT 0,
                can_download TINYINT(1) NOT NULL DEFAULT 1,
                password_hash VARCHAR(255) DEFAULT NULL,
                expires_at DATETIME DEFAULT NULL,
                created_by INT UNSIGNED DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                revoked_at DATETIME DEFAULT NULL,
                INDEX idx_created_at (created_at),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}
