<?php

namespace App\Models;

use App\Database;

class Contact
{
    public function save($company, $name, $email, $phone, $budget, $message)
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("INSERT INTO contacts (company, name, email, phone, budget, message) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$company, $name, $email, $phone, $budget, $message]);
    }
}

?>