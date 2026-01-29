<?php

namespace App\Models;

use App\Database;
use PDO;

class BlockedIpModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->createTableIfNotExists();
    }

    public function isBlocked(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        $this->cleanupExpired();

        $stmt = $this->db->prepare("
            SELECT blocked_until
            FROM blocked_ips
            WHERE ip = :ip
            ORDER BY blocked_until DESC
            LIMIT 1
        ");
        $stmt->execute(['ip' => $ip]);
        $blockedUntil = $stmt->fetchColumn();
        if (!$blockedUntil) {
            return false;
        }

        return strtotime((string)$blockedUntil) > time();
    }

    public function blockIfNotBlocked(string $ip, int $blockMinutes, string $reason = ''): void
    {
        if ($ip === '') {
            return;
        }

        if ($this->isBlocked($ip)) {
            return;
        }

        $blockMinutes = max(1, (int)$blockMinutes);
        $stmt = $this->db->prepare("
            INSERT INTO blocked_ips (ip, reason, blocked_until)
            VALUES (:ip, :reason, DATE_ADD(NOW(), INTERVAL {$blockMinutes} MINUTE))
        ");
        $stmt->execute([
            'ip'     => $ip,
            'reason' => $reason,
        ]);
    }

    private function cleanupExpired(): void
    {
        $this->db->exec("DELETE FROM blocked_ips WHERE blocked_until <= NOW()");
    }

    private function createTableIfNotExists(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS blocked_ips (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45) NOT NULL,
                reason VARCHAR(255) DEFAULT NULL,
                blocked_until DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip (ip),
                INDEX idx_blocked_until (blocked_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}

?>
