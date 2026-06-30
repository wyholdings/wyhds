<?php

namespace App\Models;

use App\Database;
use PDO;

class ToolUsageModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->createTablesIfNotExists();
    }

    public function recordView(string $slug): void
    {
        $slug = trim($slug);
        if ($slug === '') {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO tool_view_stats (tool_slug, total_views, last_viewed_at)
            VALUES (:tool_slug, 1, NOW())
            ON DUPLICATE KEY UPDATE
                total_views = total_views + 1,
                last_viewed_at = NOW()
        ");
        $stmt->execute([':tool_slug' => $slug]);

        $dailyStmt = $this->db->prepare("
            INSERT INTO tool_view_daily (tool_slug, view_date, views)
            VALUES (:tool_slug, CURDATE(), 1)
            ON DUPLICATE KEY UPDATE views = views + 1
        ");
        $dailyStmt->execute([':tool_slug' => $slug]);
    }

    public function getPopularSlugs(int $limit = 8, int $days = 30): array
    {
        $limit = max(1, $limit);
        $days = max(1, $days);

        $stmt = $this->db->prepare("
            SELECT tool_slug, SUM(views) AS views
            FROM tool_view_daily
            WHERE view_date >= (CURDATE() - INTERVAL {$days} DAY)
            GROUP BY tool_slug
            ORDER BY views DESC, tool_slug ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): string => (string)$row['tool_slug'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getViewCounts(array $slugs = []): array
    {
        if (empty($slugs)) {
            $stmt = $this->db->query("SELECT tool_slug, total_views FROM tool_view_stats");
            return $this->rowsToCounts($stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        $placeholders = [];
        $params = [];
        foreach (array_values(array_unique($slugs)) as $index => $slug) {
            $key = ':slug' . $index;
            $placeholders[] = $key;
            $params[$key] = $slug;
        }

        $stmt = $this->db->prepare("
            SELECT tool_slug, total_views
            FROM tool_view_stats
            WHERE tool_slug IN (" . implode(',', $placeholders) . ")
        ");
        $stmt->execute($params);

        return $this->rowsToCounts($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getTotalViews(int $days = 30): int
    {
        $days = max(1, $days);

        $stmt = $this->db->query("
            SELECT COALESCE(SUM(views), 0)
            FROM tool_view_daily
            WHERE view_date >= (CURDATE() - INTERVAL {$days} DAY)
        ");

        return (int)$stmt->fetchColumn();
    }

    public function getTopTools(int $limit = 20, int $days = 30): array
    {
        $limit = max(1, $limit);
        $days = max(1, $days);

        $stmt = $this->db->prepare("
            SELECT tool_slug, SUM(views) AS views, MAX(view_date) AS last_view_date
            FROM tool_view_daily
            WHERE view_date >= (CURDATE() - INTERVAL {$days} DAY)
            GROUP BY tool_slug
            ORDER BY views DESC, tool_slug ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDailyViews(int $days = 14): array
    {
        $days = max(1, $days);

        $stmt = $this->db->query("
            SELECT view_date, SUM(views) AS views
            FROM tool_view_daily
            WHERE view_date >= (CURDATE() - INTERVAL {$days} DAY)
            GROUP BY view_date
            ORDER BY view_date ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function rowsToCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row['tool_slug']] = (int)$row['total_views'];
        }
        return $counts;
    }

    private function createTablesIfNotExists(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS tool_view_stats (
                tool_slug VARCHAR(120) NOT NULL PRIMARY KEY,
                total_views INT UNSIGNED NOT NULL DEFAULT 0,
                last_viewed_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_total_views (total_views),
                INDEX idx_last_viewed_at (last_viewed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS tool_view_daily (
                tool_slug VARCHAR(120) NOT NULL,
                view_date DATE NOT NULL,
                views INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (tool_slug, view_date),
                INDEX idx_view_date_views (view_date, views)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}
