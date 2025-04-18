<?php

namespace App\Models;

use App\Database;
use PDO;

class AdminModel
{   
    public function getByUsername(string $username): ?array 
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("SELECT * FROM admins WHERE username = :username");
        $stmt->execute(['username' => $username]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

?>