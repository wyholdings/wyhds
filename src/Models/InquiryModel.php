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