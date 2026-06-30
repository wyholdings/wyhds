<?php

namespace App\Models;

use App\Database;
use PDO;
use App\Models\BlockedIpModel;

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

    public function updateStatusCode(
        int $id,
        int $statusCode,
        string $ip = '',
        int $windowMinutes = 10,
        int $threshold = 10,
        int $blockMinutes = 60
    ): void {
        if ($id <= 0 || $statusCode <= 0) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE visitor_logs
            SET status_code = :status_code,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            'status_code' => $statusCode,
            'id'          => $id,
        ]);

        if ($statusCode !== 404 || $ip === '') {
            return;
        }

        $windowMinutes = max(1, (int)$windowMinutes);
        $threshold = max(1, (int)$threshold);
        $blockMinutes = max(1, (int)$blockMinutes);

        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM visitor_logs
            WHERE ip = :ip
              AND status_code = 404
              AND visited_at >= (NOW() - INTERVAL {$windowMinutes} MINUTE)
        ");
        $countStmt->execute(['ip' => $ip]);
        $count = (int)$countStmt->fetchColumn();

        if ($count < $threshold) {
            return;
        }

        $blockModel = new BlockedIpModel();
        $blockModel->blockIfNotBlocked($ip, $blockMinutes, "Auto block: {$threshold}+ 404s in {$windowMinutes}m");
    }

    public function getLogs(int $limit = 100, int $offset = 0, string $keyword = '', bool $onlyWithDuration = false, bool $includeOfficeIp = true): array
    {
        [$whereSql, $params] = $this->buildWhere($keyword, $onlyWithDuration, $includeOfficeIp);
        $stmt = $this->db->prepare("
            SELECT id, path, query_string, referer, user_agent, ip, session_id, duration_seconds, status_code, visited_at
            FROM visitor_logs
            {$whereSql}
            ORDER BY id DESC
            LIMIT :offset, :limit
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countLogs(string $keyword = '', bool $onlyWithDuration = false, bool $includeOfficeIp = true): int
    {
        [$whereSql, $params] = $this->buildWhere($keyword, $onlyWithDuration, $includeOfficeIp);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM visitor_logs {$whereSql}");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function getToolTrafficSummary(int $days = 30): array
    {
        $days = max(1, $days);
        $searchSql = $this->searchRefererSql();

        $stmt = $this->db->query("
            SELECT
                COUNT(*) AS visits,
                COUNT(DISTINCT NULLIF(session_id, '')) AS sessions,
                COALESCE(ROUND(AVG(NULLIF(duration_seconds, 0))), 0) AS avg_duration,
                SUM(CASE WHEN {$searchSql} THEN 1 ELSE 0 END) AS search_visits
            FROM visitor_logs
            WHERE path LIKE '/tools%'
              AND visited_at >= (NOW() - INTERVAL {$days} DAY)
        ");

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'visits' => (int)($row['visits'] ?? 0),
            'sessions' => (int)($row['sessions'] ?? 0),
            'avg_duration' => (int)($row['avg_duration'] ?? 0),
            'search_visits' => (int)($row['search_visits'] ?? 0),
        ];
    }

    public function getTopToolLandingPages(int $limit = 20, int $days = 30): array
    {
        $limit = max(1, $limit);
        $days = max(1, $days);
        $searchSql = $this->searchRefererSql();

        $stmt = $this->db->prepare("
            SELECT
                path,
                COUNT(*) AS visits,
                COALESCE(ROUND(AVG(NULLIF(duration_seconds, 0))), 0) AS avg_duration,
                SUM(CASE WHEN {$searchSql} THEN 1 ELSE 0 END) AS search_visits,
                MAX(visited_at) AS last_visited_at
            FROM visitor_logs
            WHERE path LIKE '/tools/%'
              AND path NOT LIKE '/tools/category/%'
              AND visited_at >= (NOW() - INTERVAL {$days} DAY)
            GROUP BY path
            ORDER BY visits DESC, path ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSearchRefererSummary(int $limit = 10, int $days = 30): array
    {
        $limit = max(1, $limit);
        $days = max(1, $days);
        $searchSql = $this->searchRefererSql();

        $stmt = $this->db->prepare("
            SELECT
                CASE
                    WHEN referer LIKE '%google.%' THEN 'Google'
                    WHEN referer LIKE '%naver.%' THEN 'Naver'
                    WHEN referer LIKE '%daum.%' OR referer LIKE '%kakao.%' THEN 'Daum/Kakao'
                    WHEN referer LIKE '%bing.%' THEN 'Bing'
                    ELSE 'Other Search'
                END AS source,
                COUNT(*) AS visits,
                COUNT(DISTINCT path) AS pages
            FROM visitor_logs
            WHERE path LIKE '/tools%'
              AND {$searchSql}
              AND visited_at >= (NOW() - INTERVAL {$days} DAY)
            GROUP BY source
            ORDER BY visits DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getToolDailyVisits(int $days = 14): array
    {
        $days = max(1, $days);
        $searchSql = $this->searchRefererSql();

        $stmt = $this->db->query("
            SELECT
                DATE(visited_at) AS visit_date,
                COUNT(*) AS visits,
                SUM(CASE WHEN {$searchSql} THEN 1 ELSE 0 END) AS search_visits
            FROM visitor_logs
            WHERE path LIKE '/tools%'
              AND visited_at >= (NOW() - INTERVAL {$days} DAY)
            GROUP BY DATE(visited_at)
            ORDER BY visit_date ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                status_code SMALLINT UNSIGNED DEFAULT NULL,
                visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_visited_at (visited_at),
                INDEX idx_path (path)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->ensureColumnExists('status_code', 'SMALLINT UNSIGNED DEFAULT NULL');
        $this->ensureIndexExists('idx_ip_status_time', 'ip, status_code, visited_at');
    }

    private function buildWhere(string $keyword, bool $onlyWithDuration, bool $includeOfficeIp): array
    {
        $conditions = [];
        $params = [];

        if ($onlyWithDuration) {
            $conditions[] = 'duration_seconds IS NOT NULL AND duration_seconds > 0';
        }

        if (!$includeOfficeIp) {
            $conditions[] = "TRIM(COALESCE(ip, '')) <> :office_ip";
            $params[':office_ip'] = '218.148.208.139';
        }

        $keyword = trim($keyword);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $conditions[] = '(path LIKE :kw OR query_string LIKE :kw OR referer LIKE :kw OR user_agent LIKE :kw OR ip LIKE :kw OR session_id LIKE :kw)';
            $params[':kw'] = $like;
        }

        if (empty($conditions)) {
            return ['', []];
        }

        return ['WHERE ' . implode(' AND ', $conditions), $params];
    }

    private function searchRefererSql(): string
    {
        return "(referer LIKE '%google.%' OR referer LIKE '%naver.%' OR referer LIKE '%daum.%' OR referer LIKE '%kakao.%' OR referer LIKE '%bing.%')";
    }

    private function ensureColumnExists(string $column, string $definition): void
    {
        $stmt = $this->db->prepare("SHOW COLUMNS FROM visitor_logs LIKE :column");
        $stmt->execute(['column' => $column]);
        if ($stmt->fetch()) {
            return;
        }

        $this->db->exec("ALTER TABLE visitor_logs ADD COLUMN {$column} {$definition}");
    }

    private function ensureIndexExists(string $indexName, string $columnsSql): void
    {
        $stmt = $this->db->prepare("SHOW INDEX FROM visitor_logs WHERE Key_name = :idx");
        $stmt->execute(['idx' => $indexName]);
        if ($stmt->fetch()) {
            return;
        }

        $this->db->exec("CREATE INDEX {$indexName} ON visitor_logs ({$columnsSql})");
    }
}

?>
