<?php

namespace App\Models;

use App\Database;
use PDO;

class InquiryModel
{
    //문의 전체 목록 조회
    public function getAll()
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->query("SELECT * FROM contacts ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    public function getRecent(int $limit = 8): array
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("SELECT * FROM contacts ORDER BY created_at DESC, id DESC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSummary(): array
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->query("
            SELECT
                COUNT(*) AS total,
                SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS recent_7
            FROM contacts
        ");
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int)($summary['total'] ?? 0),
            'recent_7' => (int)($summary['recent_7'] ?? 0),
        ];
    }

    // 문의 정보를 데이터베이스에서 가져오는 메서드
    public function getInquiry($id)
    {

        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("SELECT * FROM contacts WHERE id = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC); // 문의 정보를 배열로 반환
    }

    // 문의 삭제
    public function deleteInquiry(int $id): bool
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("DELETE FROM contacts WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

}

?>
