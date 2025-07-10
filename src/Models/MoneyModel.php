<?php

namespace App\Models;

use App\Database;
use PDO;

class MoneyModel
{
    //문의 전체 목록 조회
    public function getAll($member_name, $type, $year)
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("SELECT * FROM account_book WHERE member_name = ? AND type = ? AND YEAR(date) = ? ORDER BY date ASC, created_at DESC");
        $stmt->execute([$member_name, $type, $year]);

        return $stmt->fetchAll();
    }

    public function getSumByMember($member_name, $type, $year) 
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("
            SELECT 
                SUM(amount) AS amount_sum, 
                SUM(vat) AS vat_sum, 
                SUM(amount + vat) AS total_sum 
            FROM account_book 
            WHERE member_name = ? AND type = ? AND YEAR(date) = ?
        ");
        $stmt->execute([$member_name, $type, $year]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function add($type, $amount, $vat, $date, $company, $product, $member_name) 
    {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO account_book (member_name, type, amount, vat, date, company, product) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        if ($stmt->execute([$member_name, $type, $amount, $vat, $date, $company, $product])) {
            return $db->lastInsertId();  // ← 여기서 삽입된 ID 반환
        }

        return false;
    }

    public function delete($id) 
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("
            DELETE FROM account_book WHERE id = ?
        ");
        return $stmt->execute([$id]);
    }

    
}

?>