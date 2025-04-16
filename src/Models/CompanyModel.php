<?php

namespace App\Models;

use App\Database;
use PDO;

class CompanyModel
{
    //업체 전체 목록 조회
    public function getAll()
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->query("SELECT * FROM companies ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    //업체 등록
    public function insert(array $data): bool
    {

        $db = Database::getInstance()->getConnection();

        $sql = "INSERT INTO companies 
            (name, business_number, type, contract_start, contract_end, manager, phone, email, address, status, memo, created_at, updated_at)
            VALUES 
            (:name, :business_number, :type, :contract_start, :contract_end, :manager, :phone, :email, :address, :status, :memo, NOW(), NOW())";

        $stmt = $db->prepare($sql);

        return $stmt->execute([
            ':name'            => $data['name'],
            ':business_number' => $data['business_number'],
            ':type'            => $data['type'],
            ':contract_start'  => $data['contract_start'],
            ':contract_end'    => $data['contract_end'],
            ':manager'         => $data['manager'],
            ':phone'           => $data['phone'],
            ':email'           => $data['email'],
            ':address'         => $data['address'],
            ':status'          => $data['status'],
            ':memo'            => $data['memo'],
        ]);
    }

    // 업체 정보를 데이터베이스에서 가져오는 메서드
    public function getCompany($companyId)
    {

        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("SELECT * FROM companies WHERE id = :id");
        $stmt->bindParam(':id', $companyId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC); // 업체 정보를 배열로 반환
    }

    // 업체 수정
    public function updateCompany(int $id, array $data): bool
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("
            UPDATE companies SET
                name = :name,
                business_number = :business_number,
                type = :type,
                contract_start = :contract_start,
                contract_end = :contract_end,
                manager = :manager,
                phone = :phone,
                email = :email,
                address = :address,
                status = :status,
                memo = :memo,
                updated_at = NOW()
            WHERE id = :id
        ");

        $data['id'] = $id;

        return $stmt->execute($data);
    }

    // 업체 삭제
    public function deleteCompany(int $id): bool
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("DELETE FROM companies WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

}

?>