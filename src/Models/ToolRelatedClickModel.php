<?php

namespace App\Models;

use App\Database;
use PDO;

class ToolRelatedClickModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->createTableIfNotExists();
    }

    public function record(
        string $sourceSlug,
        string $targetSlug,
        string $context,
        string $path,
        string $referer,
        string $sessionId,
        string $ip
    ): void {
        $sourceSlug = trim($sourceSlug);
        $targetSlug = trim($targetSlug);

        if ($sourceSlug === '' || $targetSlug === '' || $sourceSlug === $targetSlug) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO tool_related_clicks
                (source_tool_slug, target_tool_slug, context, path, referer, session_id, ip)
            VALUES
                (:source_tool_slug, :target_tool_slug, :context, :path, :referer, :session_id, :ip)
        ");
        $stmt->execute([
            ':source_tool_slug' => $sourceSlug,
            ':target_tool_slug' => $targetSlug,
            ':context' => $context,
            ':path' => $path,
            ':referer' => $referer,
            ':session_id' => $sessionId,
            ':ip' => $ip,
        ]);
    }

    public function getSummary(int $limit = 20, int $days = 30): array
    {
        $limit = max(1, $limit);
        $days = max(1, $days);

        $stmt = $this->db->prepare("
            SELECT
                source_tool_slug,
                target_tool_slug,
                COUNT(*) AS clicks,
                COUNT(DISTINCT NULLIF(session_id, '')) AS sessions,
                MAX(clicked_at) AS last_clicked_at
            FROM tool_related_clicks
            WHERE clicked_at >= (NOW() - INTERVAL {$days} DAY)
            GROUP BY source_tool_slug, target_tool_slug
            ORDER BY clicks DESC, source_tool_slug ASC, target_tool_slug ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTotalClicks(int $days = 30): int
    {
        $days = max(1, $days);
        $stmt = $this->db->query("
            SELECT COALESCE(COUNT(*), 0)
            FROM tool_related_clicks
            WHERE clicked_at >= (NOW() - INTERVAL {$days} DAY)
        ");

        return (int)$stmt->fetchColumn();
    }

    public function getDailyClicks(int $days = 14): array
    {
        $days = max(1, $days);
        $stmt = $this->db->query("
            SELECT DATE(clicked_at) AS click_date, COUNT(*) AS clicks
            FROM tool_related_clicks
            WHERE clicked_at >= (NOW() - INTERVAL {$days} DAY)
            GROUP BY DATE(clicked_at)
            ORDER BY click_date ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function createTableIfNotExists(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS tool_related_clicks (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_tool_slug VARCHAR(120) NOT NULL,
                target_tool_slug VARCHAR(120) NOT NULL,
                context VARCHAR(60) NOT NULL DEFAULT 'related',
                path VARCHAR(255) DEFAULT NULL,
                referer TEXT DEFAULT NULL,
                session_id VARCHAR(128) DEFAULT NULL,
                ip VARCHAR(45) DEFAULT NULL,
                clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_source_target_time (source_tool_slug, target_tool_slug, clicked_at),
                INDEX idx_clicked_at (clicked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}
