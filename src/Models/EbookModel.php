<?php

namespace App\Models;

use App\Database;
use PDO;

class EbookModel
{
    //Ebook list
    public function getAll()
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("SELECT * FROM ebooks ORDER BY created_at DESC");
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function upload($data)
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("INSERT INTO ebooks (file_name, folder_name) VALUES (?, ?)");
        $stmt->execute([$data['file_name'], $data['folder_name']]);
    }
}
?>